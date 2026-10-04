<?php

namespace App\Services\Billing\Providers;

use App\Enums\BillingEventType;
use App\Enums\PaymentStatus;
use App\Models\BillingCustomer;
use App\Services\Billing\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * SaaS-4: a deterministic, offline provider for development and tests (refused elsewhere by the
 * manager). It behaves like a real adapter: idempotent references derived from idempotency keys,
 * a payment registry that answers fetchPayment(), refunds, a switchable outage, and webhooks
 * signed with HMAC-SHA256 over "timestamp.body" in the Fake-Signature header (t=…,v1=…).
 *
 * Payload: {"id", "type": "payment.succeeded|payment.failed|payment.refunded|…", "created",
 * "data": {"payment", "reference", "customer", "amount", "refunded", "currency", "failure_code",
 * "failure_message"}} — amounts as decimal strings, like most providers.
 */
class FakeBillingProvider implements BillingProvider
{
    public const string SIGNATURE_HEADER = 'Fake-Signature';

    /**
     * Simulates a provider outage: every API call throws ProviderUnavailable.
     */
    public bool $outage = false;

    /**
     * @var array<string, ProviderPaymentState> provider payment ref => state
     */
    public array $payments = [];

    public function name(): string
    {
        return 'fake';
    }

    public function createCustomer(BillingCustomer $customer, string $idempotencyKey): string
    {
        $this->assertReachable();

        return 'fcus_'.substr(hash('sha256', $idempotencyKey), 0, 20);
    }

    public function collect(PaymentRequest $request): ProviderPaymentState
    {
        $this->assertReachable();
        $ref = 'fpay_'.substr(hash('sha256', $request->idempotencyKey), 0, 20);

        return $this->payments[$ref] ??= new ProviderPaymentState($ref, PaymentStatus::Pending, $request->amount, null, $request->reference);
    }

    public function refund(string $providerPaymentRef, Money $amount, string $idempotencyKey): ProviderPaymentState
    {
        $this->assertReachable();
        $payment = $this->payments[$providerPaymentRef] ?? throw new ProviderUnavailable('Unknown payment.');
        $refunded = ($payment->refunded ?? Money::zero($amount->currency))->plus($amount);

        return $this->payments[$providerPaymentRef] = new ProviderPaymentState(
            $providerPaymentRef,
            $refunded->equals($payment->amount) ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
            $payment->amount,
            $refunded,
            $payment->reference,
        );
    }

    public function fetchPayment(?string $providerPaymentRef, string $reference): ?ProviderPaymentState
    {
        $this->assertReachable();

        if ($providerPaymentRef !== null) {
            return $this->payments[$providerPaymentRef] ?? null;
        }

        foreach ($this->payments as $payment) {
            if ($payment->reference === $reference) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * Test/dev control: the provider settles a payment (what its dashboard or bank would do).
     */
    public function settle(string $providerPaymentRef, PaymentStatus $status, ?string $failureCode = null): ProviderPaymentState
    {
        $payment = $this->payments[$providerPaymentRef] ?? throw new InvalidArgumentException('Unknown payment.');

        return $this->payments[$providerPaymentRef] = new ProviderPaymentState($providerPaymentRef, $status, $payment->amount, $payment->refunded, $payment->reference, $failureCode, $failureCode !== null ? 'Payment declined' : null);
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) config('billing.providers.fake.webhook_secret');
        $header = (string) $request->headers->get(self::SIGNATURE_HEADER, '');

        if ($secret === '' || preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/', $header, $parts) !== 1) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $parts[1]) > (int) config('billing.webhooks.tolerance_seconds', 300)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $parts[1].'.'.$request->getContent(), $secret), $parts[2]);
    }

    public function webhookPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null)) {
            throw new InvalidArgumentException('Not a provider event.');
        }

        return $payload;
    }

    public function normalize(array $payload): NormalizedBillingEvent
    {
        $type = match ($payload['type'] ?? null) {
            'payment.succeeded' => BillingEventType::PaymentSucceeded,
            'payment.failed' => BillingEventType::PaymentFailed,
            'payment.refunded' => BillingEventType::PaymentRefunded,
            default => BillingEventType::Unsupported,
        };
        $data = (array) ($payload['data'] ?? []);
        $occurredAt = is_int($payload['created'] ?? null) ? CarbonImmutable::createFromTimestamp($payload['created']) : null;
        $payment = null;

        if ($type !== BillingEventType::Unsupported && is_string($data['payment'] ?? null)) {
            $currency = (string) ($data['currency'] ?? '');
            $payment = new ProviderPaymentState(
                $data['payment'],
                match ($type) {
                    BillingEventType::PaymentSucceeded => PaymentStatus::Succeeded,
                    BillingEventType::PaymentFailed => PaymentStatus::Failed,
                    default => isset($data['refunded'], $data['amount']) && $data['refunded'] === $data['amount'] ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
                },
                isset($data['amount']) ? Money::parse((string) $data['amount'], $currency) : null,
                isset($data['refunded']) ? Money::parse((string) $data['refunded'], $currency) : null,
                is_string($data['reference'] ?? null) ? $data['reference'] : null,
                is_string($data['failure_code'] ?? null) ? $data['failure_code'] : null,
                is_string($data['failure_message'] ?? null) ? $data['failure_message'] : null,
            );
        }

        return new NormalizedBillingEvent((string) $payload['id'], (string) $payload['type'], $type, $occurredAt, $payment, is_string($data['customer'] ?? null) ? $data['customer'] : null);
    }

    public function paymentMethodUrl(string $providerCustomerRef): ?string
    {
        return null;
    }

    /**
     * Test/dev helper: the Fake-Signature header value for $body at $timestamp.
     */
    public static function signature(string $body, int $timestamp, string $secret): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function assertReachable(): void
    {
        if ($this->outage) {
            throw new ProviderUnavailable('The billing provider is unavailable.');
        }
    }
}
