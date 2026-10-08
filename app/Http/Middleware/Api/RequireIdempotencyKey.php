<?php

namespace App\Http\Middleware\Api;

use App\Services\Api\ApiException;
use App\Services\Api\IdempotencyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * SaaS-6: a mutation runs once per Idempotency-Key (IdempotencyService) — the header is required.
 * A completed response (2xx–4xx) is stored for replay; an exception or a 5xx releases the key.
 */
class RequireIdempotencyKey
{
    public function __construct(private readonly IdempotencyService $idempotency) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->headers->get('Idempotency-Key', '');

        if ($key === '') {
            throw new ApiException('idempotency_key_required', 'Send an Idempotency-Key header with this request.', 400);
        }

        $principal = AuthenticateApiCredential::principal($request);
        $claim = $this->idempotency->begin($principal->credential, $key, $request->getMethod(), (string) $request->route()?->getName().':'.$request->path(), $request->getContent());

        if ($claim instanceof JsonResponse) {
            return $claim;
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->idempotency->release($claim);

            throw $e;
        }

        if ($response instanceof JsonResponse && $response->getStatusCode() < 500 && $response->exception === null) {
            $this->idempotency->complete($claim, $response);
        } else {
            $this->idempotency->release($claim);
        }

        return $response;
    }
}
