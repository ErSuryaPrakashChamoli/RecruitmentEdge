<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value', 'type', 'group', 'description'])]
class RecruitmentSetting extends Model
{
    public const string CACHE_PREFIX = 'recruitment_setting:';

    /**
     * Resolve a setting value by key, cast to its configured type.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever(
            self::CACHE_PREFIX.$key,
            function () use ($key, $default) {
                $setting = self::query()->where('key', $key)->first();

                return $setting === null ? $default : $setting->castValue();
            },
        );
    }

    /**
     * Create or update a setting. Cache invalidation happens in `booted()` for every save path
     * (this helper, direct Eloquent writes, and Filament's edit form), not just this method.
     */
    public static function put(string $key, mixed $value, string $type = 'string', string $group = 'general', ?string $description = null): self
    {
        return self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'type' => $type, 'group' => $group, 'description' => $description],
        );
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => Cache::forget(self::CACHE_PREFIX.$setting->key));
        static::deleted(fn (self $setting) => Cache::forget(self::CACHE_PREFIX.$setting->key));
    }

    protected function castValue(): mixed
    {
        return match ($this->type) {
            'int', 'integer' => (int) $this->value,
            'bool', 'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
