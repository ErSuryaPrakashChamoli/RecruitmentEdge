<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\Actions;

use App\Enums\IncentiveCalculationStatus;
use App\Models\RecruiterIncentiveCalculation;
use App\Services\IncentiveApprovalService;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Carbon;

/**
 * Dedicated incentive lifecycle actions (Submit for Verification, Approve, Mark Payable, Record
 * Payment, Reject, Reverse) shared by the calculations table and the view page header. Each is
 * visible only in the right status AND with the matching RecruiterIncentiveCalculationPolicy
 * ability; IncentiveApprovalService re-checks the transition server-side. Record Payment is the
 * only way to reach Paid.
 */
class IncentiveLifecycleActions
{
    /**
     * @return array<int, Action>
     */
    public static function make(): array
    {
        return [
            self::transition(
                'submitForVerification',
                'Submit for Verification',
                [IncentiveCalculationStatus::Calculated],
                'heroicon-o-paper-airplane',
                'primary',
                fn (IncentiveApprovalService $service, RecruiterIncentiveCalculation $record, array $data) => $service->submitForVerification($record, auth()->user()?->employee, $data['remarks'] ?? null),
            ),
            self::transition(
                'approve',
                'Approve',
                [IncentiveCalculationStatus::PendingVerification],
                'heroicon-o-check-badge',
                'success',
                fn (IncentiveApprovalService $service, RecruiterIncentiveCalculation $record, array $data) => $service->approve($record, auth()->user()?->employee, $data['remarks'] ?? null),
            ),
            self::transition(
                'markPayable',
                'Mark Payable',
                [IncentiveCalculationStatus::Approved],
                'heroicon-o-currency-rupee',
                'info',
                fn (IncentiveApprovalService $service, RecruiterIncentiveCalculation $record, array $data) => $service->markPayable($record, auth()->user()?->employee, $data['remarks'] ?? null),
            ),
            self::recordPayment(),
            self::transition(
                'reject',
                'Reject',
                [IncentiveCalculationStatus::Calculated, IncentiveCalculationStatus::PendingVerification],
                'heroicon-o-x-circle',
                'danger',
                fn (IncentiveApprovalService $service, RecruiterIncentiveCalculation $record, array $data) => $service->reject($record, (string) ($data['remarks'] ?? ''), auth()->user()?->employee),
                remarksRequired: true,
            ),
            self::transition(
                'reverse',
                'Reverse',
                [IncentiveCalculationStatus::Approved, IncentiveCalculationStatus::Payable, IncentiveCalculationStatus::Paid],
                'heroicon-o-arrow-uturn-left',
                'danger',
                fn (IncentiveApprovalService $service, RecruiterIncentiveCalculation $record, array $data) => $service->reverse($record, (string) ($data['remarks'] ?? ''), auth()->user()?->employee),
                remarksRequired: true,
            ),
        ];
    }

    public static function group(): ActionGroup
    {
        return ActionGroup::make(self::make())
            ->label('Status')
            ->icon('heroicon-o-arrow-path')
            ->color('gray');
    }

    /**
     * @param  array<int, IncentiveCalculationStatus>  $from
     * @param  Closure(IncentiveApprovalService, RecruiterIncentiveCalculation, array<string, mixed>): mixed  $operation
     */
    private static function transition(
        string $name,
        string $label,
        array $from,
        string $icon,
        string $color,
        Closure $operation,
        bool $remarksRequired = false,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (RecruiterIncentiveCalculation $record): bool => in_array($record->status, $from, true)
                && (bool) auth()->user()?->can($name, $record))
            ->modalHeading("{$label}: incentive")
            ->modalSubmitActionLabel($label)
            ->schema([
                Textarea::make('remarks')
                    ->label('Remarks')
                    ->required($remarksRequired)
                    ->rows(3),
            ])
            ->action(fn (RecruiterIncentiveCalculation $record, array $data) => self::perform(
                fn () => $operation(app(IncentiveApprovalService::class), $record, $data),
                "Incentive: {$label} done",
            ));
    }

    private static function recordPayment(): Action
    {
        return Action::make('recordPayment')
            ->label('Record Payment')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (RecruiterIncentiveCalculation $record): bool => $record->status === IncentiveCalculationStatus::Payable
                && (bool) auth()->user()?->can('recordPayment', $record))
            ->modalHeading('Record payment: incentive')
            ->modalSubmitActionLabel('Record Payment')
            ->schema(fn (RecruiterIncentiveCalculation $record): array => [
                TextInput::make('amount')
                    ->numeric()
                    ->minValue(0.01)
                    ->default($record->effectiveAmount())
                    ->required(),
                DatePicker::make('payment_date')
                    ->default(now())
                    ->required(),
                TextInput::make('payment_reference')
                    ->maxLength(255)
                    ->required(),
                Textarea::make('remarks')
                    ->rows(2),
            ])
            ->action(fn (RecruiterIncentiveCalculation $record, array $data) => self::perform(
                fn () => app(IncentiveApprovalService::class)->pay(
                    $record,
                    (float) $data['amount'],
                    Carbon::parse($data['payment_date']),
                    $data['payment_reference'] ?? null,
                    auth()->user()?->employee,
                    filled($data['remarks'] ?? null) ? $data['remarks'] : null,
                ),
                'Payment recorded',
            ));
    }

    /**
     * IncentiveApprovalService enforces the transition allow-list, required remarks/reference and
     * retention holds; surface its DomainException as a notification and halt instead of letting
     * it render a 500 page.
     *
     * @param  Closure(): mixed  $operation
     */
    public static function perform(Closure $operation, string $successTitle): void
    {
        try {
            $operation();
        } catch (DomainException $e) {
            Notification::make()
                ->title('Incentive could not be updated')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
