<?php

namespace App\Filament\Platform\Pages;

use App\Enums\InvoiceStatus;
use App\Enums\PlatformCapability;
use App\Enums\SubscriptionSource;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\BillingInvoice;
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
use Filament\Infolists\Components\KeyValueEntry;
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
 * Platform commercial UI: every tenant's invoices as SaaS-4 issued them — the stored snapshot
 * (totals, tax as recorded, currency, dates), never recomputed from current prices. Recording an
 * offline payment of the whole balance and voiding an unpaid invoice go through
 * TenantCommercialService to PaymentService / InvoiceService, which decide.
 */
class Invoices extends Page implements HasTable
{
    use InteractsWithPlatform;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Invoices';

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
            ->query(fn (): Builder => app(PlatformDirectory::class)->invoices()->when($this->tenantFilter !== null, fn (Builder $query) => $query->where('tenant_id', $this->tenantFilter)))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('Invoice')->searchable(),
                TextColumn::make('tenant.name')->label('Tenant')->description(fn (BillingInvoice $record): ?string => $record->tenant?->slug),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                    ->color(fn (InvoiceStatus $state, BillingInvoice $record): string => self::statusColor($record)),
                TextColumn::make('subtotal_minor')->label('Subtotal')->formatStateUsing(fn (int $state, BillingInvoice $record): string => Money::of($state, $record->currency)->format())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tax_minor')->label('Tax (as recorded)')->formatStateUsing(fn (int $state, BillingInvoice $record): string => Money::of($state, $record->currency)->format())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_minor')->label('Total')->formatStateUsing(fn (int $state, BillingInvoice $record): string => Money::of($state, $record->currency)->format()),
                TextColumn::make('amount_paid_minor')->label('Paid')->formatStateUsing(fn (int $state, BillingInvoice $record): string => Money::of($state, $record->currency)->format()),
                TextColumn::make('amount_due_minor')->label('Balance')->formatStateUsing(fn (int $state, BillingInvoice $record): string => Money::of($state, $record->currency)->format()),
                TextColumn::make('issued_at')->label('Issued')->date()->sortable()->placeholder('—'),
                TextColumn::make('due_at')->label('Due')->date()->sortable()->placeholder('—'),
                TextColumn::make('paid_at')->label('Paid on')->date()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(InvoiceStatus::cases())->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->label()])->all()),
                Filter::make('overdue')->label('Due date passed')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('status', InvoiceStatus::Open->value)->where('due_at', '<', now())),
                SelectFilter::make('currency')->options(fn (): array => array_combine(Money::currencies(), Money::currencies())),
                Filter::make('issued')
                    ->schema([DatePicker::make('issued_from')->label('Issued from'), DatePicker::make('issued_until')->label('Issued until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['issued_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('issued_at', '>=', $date))
                        ->when($data['issued_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('issued_at', '<=', $date))),
            ])
            ->headerActions([
                Action::make('allTenants')
                    ->label(fn (): string => 'Tenant: '.(Tenant::query()->whereKey($this->tenantFilter)->value('slug') ?? '—').' (show all)')
                    ->visible(fn (): bool => $this->tenantFilter !== null)
                    ->color('gray')
                    ->action(fn () => $this->tenantFilter = null),
            ])
            ->recordActions([
                Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (BillingInvoice $record): string => "Invoice {$record->number}")
                    ->modalSubmitAction(false)
                    ->schema([
                        KeyValueEntry::make('invoice')->hiddenLabel()->keyLabel('Field')->valueLabel('As issued')
                            ->state(fn (BillingInvoice $record): array => self::snapshot($record)),
                    ]),
                Action::make('recordPayment')
                    ->label('Record payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (BillingInvoice $record): bool => $this->permits(PlatformCapability::CommercialManage) && $record->status === InvoiceStatus::Open && $record->amount_due_minor > 0 && $record->subscription?->source !== SubscriptionSource::Provider)
                    ->requiresConfirmation()
                    ->modalHeading(fn (BillingInvoice $record): string => "Record a payment of {$record->amountDue()->format()}")
                    ->modalDescription(fn (BillingInvoice $record): string => "WHAT: records that {$record->tenant?->name} paid the whole balance of invoice {$record->number} outside the platform (bank transfer, cheque). AMOUNT: {$record->amountDue()->format()} — the balance as stored; part payments are recorded with billing:payment. CURRENT: Open, {$record->amountPaid()->format()} paid of {$record->total()->format()}. RESULT: the invoice becomes Paid, and billing updates the subscription (for example Pending or Past due becomes Active when nothing else is owed). REVERSIBLE: no — a refund can be recorded later; the payment stays on record.")
                    ->schema([
                        TextInput::make('external_reference')->label('Bank or cheque reference')->required()->maxLength(120),
                        Textarea::make('reason')->required()->maxLength(255),
                    ])
                    ->modalSubmitActionLabel('Record payment')
                    ->action(fn (BillingInvoice $record, array $data) => self::perform(fn (User $operator) => app(TenantCommercialService::class)->recordFullPayment($record->tenant, (int) $record->getKey(), (string) $data['external_reference'], (string) $data['reason'], $operator), 'Payment recorded')),
                Action::make('void')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (BillingInvoice $record): bool => $this->permits(PlatformCapability::CommercialManage) && $record->status === InvoiceStatus::Open && $record->amount_paid_minor === 0)
                    ->requiresConfirmation()
                    ->modalHeading(fn (BillingInvoice $record): string => "Void invoice {$record->number}")
                    ->modalDescription(fn (BillingInvoice $record): string => "WHAT: {$record->tenant?->name}'s invoice {$record->number} for {$record->total()->format()} is withdrawn; nothing is due on it any more and its number stays used. CURRENT: Open, nothing paid. RESULT: Void; the subscription itself is not changed by this. REVERSIBLE: no.")
                    ->schema([
                        TextInput::make('confirm')->label('Type the invoice number to confirm')->required()->in(fn (BillingInvoice $record): array => [$record->number]),
                        Textarea::make('reason')->required()->maxLength(255),
                    ])
                    ->modalSubmitActionLabel('Void invoice')
                    ->action(fn (BillingInvoice $record, array $data) => self::perform(fn (User $operator) => app(TenantCommercialService::class)->voidInvoice($record->tenant, (int) $record->getKey(), (string) $data['reason'], $operator), 'Invoice voided')),
            ])
            ->recordUrl(null)
            ->emptyStateHeading('No invoices');
    }

    private static function statusColor(BillingInvoice $record): string
    {
        return match (true) {
            $record->status === InvoiceStatus::Paid => 'success',
            $record->status === InvoiceStatus::Open => 'warning',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    private static function snapshot(BillingInvoice $record): array
    {
        $money = fn (?int $minor): string => Money::of((int) $minor, $record->currency)->format();

        return [
            'Tenant' => (string) $record->tenant?->name,
            'Status' => $record->status->label(),
            'Description' => (string) ($record->description ?? '—'),
            'Period' => collect([$record->period_start, $record->period_end])->map(fn ($date): string => $date?->toFormattedDateString() ?? '…')->implode(' – '),
            'Subtotal' => $money($record->subtotal_minor),
            'Discount' => $money($record->discount_minor),
            'Tax (as recorded on the invoice)' => $money($record->tax_minor),
            'Total' => $money($record->total_minor),
            'Paid' => $money($record->amount_paid_minor),
            'Balance' => $money($record->amount_due_minor),
            'Issued' => $record->issued_at?->toDayDateTimeString() ?? '—',
            'Due' => $record->due_at?->toDayDateTimeString() ?? '—',
            'Paid on' => $record->paid_at?->toDayDateTimeString() ?? '—',
            'Voided' => $record->voided_at === null ? '—' : $record->voided_at->toDayDateTimeString().' — '.$record->void_reason,
        ];
    }
}
