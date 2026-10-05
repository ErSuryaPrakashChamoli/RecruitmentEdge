<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\IntegrationConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SaaS-6: one of the tenant's integration connections (a type registered in IntegrationRegistry:
 * an outbound webhook endpoint or an inbound webhook source). config holds non-secret settings;
 * secrets (current, previous and its expiry during a rotation) are encrypted at rest, hidden from
 * serialisation and never shown after they are issued. Written only by IntegrationConnectionService
 * and the delivery/receipt services (health).
 */
#[Fillable(['type', 'name', 'status', 'public_key', 'config', 'secrets', 'created_by', 'updated_by'])]
#[Hidden(['secrets'])]
class IntegrationConnection extends Model
{
    /** @use HasFactory<IntegrationConnectionFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'config' => 'array',
            'secrets' => 'encrypted:array',
            'secret_rotated_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isActive(): bool
    {
        return $this->status === ConnectionStatus::Active;
    }

    /**
     * Every secret valid now: the current one, and the previous one while its rotation overlap lasts.
     *
     * @return list<string>
     */
    public function validSecrets(): array
    {
        $secrets = (array) $this->secrets;
        $valid = array_filter([(string) ($secrets['current'] ?? '')]);
        $previousUntil = (int) ($secrets['previous_expires_at'] ?? 0);

        if (filled($secrets['previous'] ?? null) && $previousUntil > now()->getTimestamp()) {
            $valid[] = (string) $secrets['previous'];
        }

        return array_values($valid);
    }
}
