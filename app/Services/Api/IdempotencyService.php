<?php

namespace App\Services\Api;

use App\Models\ApiCredential;
use App\Models\ApiIdempotencyKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;

/**
 * SaaS-6: Idempotency-Key for API mutations. A key is claimed by inserting its row — unique per
 * (tenant, credential, key) — so of two identical requests racing, exactly one runs:
 *
 * - first time: the row is claimed (processing) and the request runs; its response is stored
 *   (encrypted) and replayed for the same key afterwards;
 * - same key, same request, already completed: the stored response is replayed (Idempotent-Replayed);
 * - same key, still running: 409 idempotency_in_progress (retry later);
 * - same key, different method, route or body: 422 idempotency_key_reused;
 * - a failed request (an exception or 5xx) releases its claim, so a retry runs again.
 *
 * A claim left by a request that died is taken over after STALE_CLAIM_SECONDS. Keys expire after
 * api.idempotency.retention_hours and are pruned by integrations:sweep.
 */
class IdempotencyService
{
    public const int STALE_CLAIM_SECONDS = 300;

    /**
     * The claimed row, or a response to return instead of running the request.
     */
    public function begin(ApiCredential $credential, string $key, string $method, string $route, string $body): ApiIdempotencyKey|JsonResponse
    {
        if (preg_match('/^[\x21-\x7E]{1,191}$/', $key) !== 1) {
            throw new ApiException('invalid_idempotency_key', 'The Idempotency-Key header must be 1–191 visible ASCII characters.', 400);
        }

        $fingerprint = hash('sha256', $method.' '.$route."\n".$body);

        try {
            return ApiIdempotencyKey::query()->create([
                'api_credential_id' => $credential->getKey(),
                'idempotency_key' => $key,
                'method' => $method,
                'route' => $route,
                'request_hash' => $fingerprint,
                'status' => 'processing',
                'expires_at' => now()->addHours((int) config('api.idempotency.retention_hours', 24)),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request holds (or held) this key.
        }

        /** @var ApiIdempotencyKey $existing */
        $existing = ApiIdempotencyKey::query()->where('api_credential_id', $credential->getKey())->where('idempotency_key', $key)->firstOrFail();

        if (! hash_equals($existing->request_hash, $fingerprint)) {
            throw new ApiException('idempotency_key_reused', 'This Idempotency-Key was used for a different request.', 422);
        }

        if ($existing->status === 'completed') {
            $stored = (array) $existing->response_encrypted;

            return response()->json($stored['body'] ?? null, (int) $existing->response_status)->header('Idempotent-Replayed', 'true');
        }

        // A claim older than STALE_CLAIM_SECONDS belongs to a request that died: take it over atomically.
        $takenOver = ApiIdempotencyKey::query()->whereKey($existing->getKey())->where('status', 'processing')
            ->where('updated_at', '<', now()->subSeconds(self::STALE_CLAIM_SECONDS))->update(['updated_at' => now()]);

        if ($takenOver === 1) {
            return $existing->fresh();
        }

        throw new ApiException('idempotency_in_progress', 'A request with this Idempotency-Key is still being processed. Retry shortly.', 409, ['Retry-After' => '5']);
    }

    public function complete(ApiIdempotencyKey $claim, JsonResponse $response): void
    {
        $claim->forceFill([
            'status' => 'completed',
            'response_status' => $response->getStatusCode(),
            'response_encrypted' => ['body' => $response->getData(true)],
        ])->save();
    }

    public function release(ApiIdempotencyKey $claim): void
    {
        ApiIdempotencyKey::query()->whereKey($claim->getKey())->where('status', 'processing')->delete();
    }
}
