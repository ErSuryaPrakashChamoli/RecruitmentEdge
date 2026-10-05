<?php

namespace Database\Factories;

use App\Enums\ApiScope;
use App\Models\ApiCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * SaaS-6 fixture: an API credential whose token is self::TOKEN_SECRET for its key id
 * (ApiCredentialService::token($credential->key_id, ApiCredentialFactory::TOKEN_SECRET)). Real
 * credentials are issued by ApiCredentialService.
 *
 * @extends Factory<ApiCredential>
 */
class ApiCredentialFactory extends Factory
{
    public const string TOKEN_SECRET = 'TestSecret0123456789TestSecret0123456789';

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Integration '.Str::random(8),
            'key_id' => Str::random(20),
            'secret_hash' => hash('sha256', self::TOKEN_SECRET),
            'scopes' => array_map(fn (ApiScope $scope): string => $scope->value, ApiScope::cases()),
            'expires_at' => now()->addDays(90),
        ];
    }
}
