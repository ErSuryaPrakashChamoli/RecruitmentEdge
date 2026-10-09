<?php

namespace App\Filament\Platform\Pages;

use App\Enums\PaymentStatus;
use App\Enums\PlatformCapability;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\BillingPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Platform\PlatformDirectory;
use App\Services\Platform\TenantCommercialService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Platform commercial UI: every tenant's payments — amount and currency as recorded, method,
 * status, references and failures. Refunding what remains of a payment goes through
 * TenantCommercialService to PaymentService, which decides (forward-only states, provider call).
 */
class Payments extends Page implements HasTable
{
    use InteractsWithPlatform;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Payments';

    #[Url(as: 'tenant')]
    public ?int $tenantFilter = null;

    public static function canAccess(): bool
    {
        return self::allows(PlatformCapability::CommercialManage);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(PlatformDirectory::class)->payments()->when($this->tenantFilter !== null, fn (Builder $query) => $query->where('tenant_id', $this->tenantFilter)))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->label('Tenant')->description(fn (BillingPayment $record): ?string => $record->tenant?->slug),
                TextColumn::make('invoice.number')->label('Invoice')->placeholder('—'),
                TextColumn::make('amount_minor')->label('Amount')->formatStateUsing(fn (int $state, BillingPayment $record): string => Money::of($state, $record->currency)->format()),
                TextColumn::make('amount_refunded_minor')->label('Refunded')->formatStateUsing(fn (int $state, BillingPayment $record): string => $state === 0 ? '—' : Money::of($state, $record->currency)->format()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
                    ->color(fn (PaymentStatus $state): string => match ($state) {
                        PaymentStatus::Succeeded => 'success',
                        PaymentStatus::Failed => 'danger',
                        PaymentStatus::Pending => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('method')->description(fn (BillingPayment $record): ?string => $record->provider),
                TextColumn::make('external_reference')->label('Reference')
                    ->state(fn (BillingPayment $record): string => $record->external_reference ?? $record->provider_payment_ref ?? $record->reference)
                    ->searchable(['external_reference', 'provider_payment_ref', 'reference'])
                    ->limit(30),
                TextColumn::make('completed_at')->label('Date')->dateTime()->placeholder('—')
                    ->state(fn (BillingPayment $record): mixed => $record->completed_at ?? $record->attempted_at),
                TextColumn::make('failure_message')->label('Failure')->placeholder('—')->limit(40)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('method')->options(['manual' => 'Manual', 'provider' => 'Provider']),
                SelectFilter::make('currency')->options(fn (): array => array_combine(Money::currencies(), Money::currencies())),
                Filter::make('attempted')
                    ->schema([DatePicker::make('from')->label('From'), DatePicker::make('until')->label('Until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('attempted_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('attempted_at', '<=', $date))),
            ])
            ->headerActions([
                Action::make('allTenants')
                    ->label(fn (): string => 'Tenant: '.(Tenant::query()->whereKey($this->tenantFilter)->value('slug') ?? '—').' (show all)')
                    ->visible(fn (): bool => $this->tenantFilter !== null)
                    ->color('gray')
                    ->action(fn () => $this->tenantFilter = null),
            ])
            ->recordActions([
                Action::make('refund')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (BillingPayment $record): bool => $this->permits(PlatformCapability::CommercialManage) && in_array($record->status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded], true) && self::remaining($record)->isZero() === false)
                    ->requiresConfirmation()
                    ->modalHeading(fn (BillingPayment $record): string => 'Refund '.self::remaining($record)->format())
                    ->modalDescription(fn (BillingPayment $record): string => "WHAT: refunds what remains of {$record->tenant?->name}'s payment on invoice {$record->invoice?->number} — ".($record->method === 'manual' ? 'recorded here as refunded outside the platform' : 'requested from the payment provider').'. AMOUNT: '.self::remaining($record)->format().' (part refunds are recorded with billing:payment). CURRENT: '.$record->status->label().', '.$record->amount()->format().' paid. RESULT: Refunded; the invoice is not re-opened and the subscription is not changed. REVERSIBLE: no.')
                    ->schema([
                        TextInput::make('confirm')->label('Type the invoice number to confirm')->required()->in(fn (BillingPayment $record): array => [(string) $record->invoice?->number]),
                        Textarea::make('reason')->required()->maxLength(255),
                    ])
                    ->modalSubmitActionLabel('Refund')
                    ->action(fn (BillingPayment $record, array $data) => self::perform(fn (User $operator) => app(TenantCommercialService::class)->refundRemaining($record->tenant, (int) $record->getKey(), (string) $data['reason'], $operator), 'Refund recorded')),
            ])
            ->recordUrl(null)
            ->emptyStateHeading('No payments');
    }

    private static function remaining(BillingPayment $record): Money
    {
        return $record->amount()->minus(Money::of((int) $record->amount_refunded_minor, $record->currency));
    }
}
