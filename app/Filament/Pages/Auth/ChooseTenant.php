<?php

namespace App\Filament\Pages\Auth;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Identity\TenantSelectionService;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * SaaS-2: the organisation chooser (/admin/organisations). Lists only the tenants the signed-in
 * identity may enter right now (User::accessibleTenants — an Active membership, a usable tenant, a
 * role there); nothing about any other tenant, and nothing about a tenant the person lost. Opening
 * one goes to its URL, where the membership is checked again; "make default" changes only the
 * person's own preference.
 */
class ChooseTenant extends SimplePage
{
    protected string $view = 'filament.pages.auth.choose-tenant';

    /**
     * Tenant-less: no topbar (its notifications are tenant-owned and there is no tenant here).
     */
    protected bool $hasTopbar = false;

    public static function getUrl(): string
    {
        return route('filament.admin.choose-tenant');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Choose an organisation';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Choose an organisation';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->defaultWasLost()
            ? 'Your default organisation is no longer available to you. Choose where to continue.'
            : 'You belong to more than one organisation. Choose where to work.';
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function tenants(): Collection
    {
        return $this->user()->accessibleTenants();
    }

    public function defaultTenantId(): ?int
    {
        $default = $this->user()->getDefaultTenant(Filament::getCurrentOrDefaultPanel());

        return $default === null ? null : (int) $default->getKey();
    }

    public function urlFor(Tenant $tenant): string
    {
        return Filament::getCurrentOrDefaultPanel()->getUrl($tenant);
    }

    public function makeDefault(int $tenantId): void
    {
        $tenant = $this->tenants()->first(fn (Tenant $tenant): bool => (int) $tenant->getKey() === $tenantId);

        try {
            if ($tenant === null) {
                throw new DomainException('You can only make an organisation you can open your default.');
            }

            app(TenantSelectionService::class)->makeDefault($this->user(), $tenant);
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title("{$tenant->name} is now your default organisation")->success()->send();
    }

    /**
     * A default is set but it is not among the tenants the person may enter.
     */
    private function defaultWasLost(): bool
    {
        $default = TenantMembership::query()->where('user_id', $this->user()->getKey())->where('is_default', true)->value('tenant_id');

        return $default !== null && ! $this->tenants()->contains(fn (Tenant $tenant): bool => (int) $tenant->getKey() === (int) $default);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
