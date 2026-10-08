<?php

namespace App\Filament\Pages;

use App\Enums\AccessState;
use App\Enums\SubscriptionStatus;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Billing\BillingAuthorization;
use App\Services\Billing\BillingCustomerService;
use App\Services\Billing\BillingStatusService;
use App\Services\Billing\Providers\BillingProviderManager;
use App\Services\Billing\SubscriptionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * SaaS-4: this organisation's subscription, invoices and payments — billing.view to see,
 * billing.manage to keep the billing details and contact current and to cancel or resume at the
 * end of the period (the services re-check every permission). Never calls the payment provider
 * while rendering. Plans, prices and refunds are platform operations: not here.
 */
class Billing extends Page
{
    use GuardsDomainExceptions;

    protected string $view = 'filament.pages.billing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Billing';

    protected static ?string $title = 'Billing';

    public static function canAccess(): bool
    {
        return BillingAuthorization::canView(Filament::auth()->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return app(BillingStatusService::class)->summary();
    }

    public function customer(): ?BillingCustomer
    {
        return BillingCustomer::query()->with('contact')->first();
    }

    /**
     * @return Collection<int, BillingInvoice>
     */
    public function invoices(): Collection
    {
        return BillingInvoice::query()->with('payments')->orderByDesc('period_start')->orderByDesc('id')->limit(36)->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editDetails')
                ->label('Billing details')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn (): bool => $this->canManage())
                ->fillForm(fn (): array => $this->customer()?->only(['legal_name', 'email', 'tax_id', 'address', 'country']) ?? [])
                ->schema([
                    TextInput::make('legal_name')->label('Legal name on invoices')->required()->maxLength(255),
                    TextInput::make('email')->label('Billing email')->email()->maxLength(255),
                    TextInput::make('tax_id')->label('Tax registration (e.g. GSTIN)')->maxLength(40),
                    Textarea::make('address')->rows(3)->maxLength(1000),
                    TextInput::make('country')->label('Country (two-letter code)')->length(2),
                ])
                ->action(function (array $data): void {
                    self::guarded('Billing details not saved', fn () => app(BillingCustomerService::class)->updateDetails([...$data, 'country' => filled($data['country'] ?? null) ? strtoupper((string) $data['country']) : null], $this->user()));
                    Notification::make()->title('Billing details saved')->success()->send();
                }),
            Action::make('changeContact')
                ->label('Billing contact')
                ->icon(Heroicon::OutlinedUserCircle)
                ->visible(fn (): bool => $this->canManage())
                ->fillForm(fn (): array => ['contact_user_id' => $this->customer()?->contact_user_id])
                ->schema([
                    Select::make('contact_user_id')
                        ->label('Member who receives invoices')
                        ->options(fn (): array => User::query()->membersOfCurrentTenant(fn ($membership) => $membership->where('status', AccessState::Active->value))->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->helperText('Being the billing contact grants no access or role.'),
                ])
                ->action(function (array $data): void {
                    $contact = filled($data['contact_user_id'] ?? null) ? User::query()->find($data['contact_user_id']) : null;
                    self::guarded('Billing contact not changed', fn () => app(BillingCustomerService::class)->setContact($contact, $this->user()));
                    Notification::make()->title('Billing contact saved')->success()->send();
                }),
            Action::make('paymentMethod')
                ->label('Payment method')
                ->icon(Heroicon::OutlinedCreditCard)
                ->visible(fn (): bool => $this->canManage() && $this->paymentMethodUrl() !== null)
                ->url(fn (): ?string => $this->paymentMethodUrl(), shouldOpenInNewTab: true),
            Action::make('cancelAtPeriodEnd')
                ->label('Cancel at period end')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('The subscription ends when the paid period ends. Your data is kept; access closes at that date unless you resume before it.')
                ->visible(fn (): bool => $this->canManage() && in_array($this->liveSubscription()?->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true))
                ->schema([Textarea::make('reason')->label('Why are you leaving? (optional)')->rows(2)->maxLength(255)])
                ->action(function (array $data): void {
                    self::guarded('Subscription not cancelled', fn () => app(SubscriptionService::class)->cancelAtPeriodEnd($this->liveSubscription(), (string) ($data['reason'] ?? ''), $this->user()));
                    Notification::make()->title('The subscription ends at the end of the period')->success()->send();
                }),
            Action::make('resume')
                ->label('Resume subscription')
                ->visible(fn (): bool => $this->canManage() && $this->liveSubscription()?->status === SubscriptionStatus::Cancelling)
                ->requiresConfirmation()
                ->action(function (): void {
                    self::guarded('Subscription not resumed', fn () => app(SubscriptionService::class)->resume($this->liveSubscription(), $this->user()));
                    Notification::make()->title('Subscription resumed')->success()->send();
                }),
        ];
    }

    private function liveSubscription(): ?BillingSubscription
    {
        return BillingSubscription::query()->where('is_live', true)->first();
    }

    private function paymentMethodUrl(): ?string
    {
        $customer = $this->customer();

        return $customer?->provider_customer_ref !== null && $customer->provider !== null
            ? app(BillingProviderManager::class)->find($customer->provider)?->paymentMethodUrl($customer->provider_customer_ref)
            : null;
    }

    private function canManage(): bool
    {
        return (bool) $this->user()?->can('billing.manage');
    }

    private function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}
