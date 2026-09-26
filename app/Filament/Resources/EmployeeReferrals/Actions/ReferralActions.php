<?php

namespace App\Filament\Resources\EmployeeReferrals\Actions;

use App\Enums\ReferralIncentiveStatus;
use App\Enums\ReferralStatus;
use App\Filament\Resources\EmployeeReferrals\Schemas\EmployeeReferralForm;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\Employee;
use App\Models\EmployeeReferral;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Services\HierarchyService;
use App\Services\ReferralService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Reviewer actions on a referral — shared by the table and the view page. Visibility mirrors
 * EmployeeReferralPolicy::review + the status's allowed transitions; ReferralService re-enforces
 * both, so a hidden button is never the only guard.
 */
class ReferralActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [self::startReview(), self::accept(), self::reject(), self::close(), self::bonusEligibility()];
    }

    public static function startReview(): Action
    {
        return Action::make('startReview')
            ->label('Start review')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->visible(fn (EmployeeReferral $record): bool => self::can($record, ReferralStatus::UnderReview))
            ->action(fn (EmployeeReferral $record) => self::perform(fn (ReferralService $s) => $s->moveTo($record, ReferralStatus::UnderReview, auth()->user()?->employee), 'Referral under review'));
    }

    public static function accept(): Action
    {
        return Action::make('acceptReferral')
            ->label('Accept into pipeline')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (EmployeeReferral $record): bool => self::can($record, ReferralStatus::Accepted))
            ->modalDescription('Creates (or links) the candidate\'s application for the position and starts tracking it through the pipeline.')
            ->schema(fn (EmployeeReferral $record): array => [
                Select::make('requisition_id')
                    ->label('Position')
                    ->options(fn (): array => EmployeeReferralForm::openPositions())
                    ->default($record->requisition_id)
                    ->required()
                    ->searchable(),
                Select::make('recruiter_id')
                    ->label('Recruiter')
                    ->options(fn (): array => self::recruiterOptions())
                    ->default(auth()->user()?->employee_id)
                    ->required()
                    ->searchable(),
            ])
            ->action(function (EmployeeReferral $record, array $data): void {
                $recruiter = Employee::query()->whereKey(array_keys(self::recruiterOptions()))->findOrFail($data['recruiter_id']);
                $requisition = RecruitmentRequisition::query()->findOrFail($data['requisition_id']);

                self::perform(fn (ReferralService $s) => $s->accept($record, $recruiter, auth()->user()?->employee, $requisition), 'Referral accepted into the pipeline');
            });
    }

    public static function reject(): Action
    {
        return Action::make('rejectReferral')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (EmployeeReferral $record): bool => self::can($record, ReferralStatus::Rejected))
            ->schema([
                Select::make('rejection_reason_id')
                    ->label('Reason')
                    ->options(fn (): array => RecruitmentRejectionReason::groupedActiveOptions())
                    ->required()
                    ->searchable(),
                Textarea::make('remarks')->maxLength(1000),
            ])
            ->action(fn (EmployeeReferral $record, array $data) => self::perform(
                fn (ReferralService $s) => $s->moveTo($record, ReferralStatus::Rejected, auth()->user()?->employee, $data['remarks'] ?? null, RecruitmentRejectionReason::query()->findOrFail($data['rejection_reason_id'])),
                'Referral rejected',
            ));
    }

    public static function close(): Action
    {
        return Action::make('closeReferral')
            ->label('Close')
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (EmployeeReferral $record): bool => self::can($record, ReferralStatus::Closed))
            ->schema([Textarea::make('remarks')->maxLength(1000)])
            ->action(fn (EmployeeReferral $record, array $data) => self::perform(fn (ReferralService $s) => $s->moveTo($record, ReferralStatus::Closed, auth()->user()?->employee, $data['remarks'] ?? null), 'Referral closed'));
    }

    /**
     * Explicit, audited referral-bonus eligibility decision (reason required). Locked once the
     * bonus has been calculated — the incentive engine's adjustments take over from there.
     */
    public static function bonusEligibility(): Action
    {
        return Action::make('bonusEligibility')
            ->label(fn (EmployeeReferral $record): string => $record->incentive_eligible ? 'Mark bonus ineligible' : 'Mark bonus eligible')
            ->icon('heroicon-o-banknotes')
            ->color('gray')
            ->visible(fn (EmployeeReferral $record): bool => $record->incentive_status !== ReferralIncentiveStatus::Calculated
                && (bool) auth()->user()?->can('review', $record))
            ->schema([Textarea::make('reason')->label('Reason (recorded in the audit log)')->required()->maxLength(1000)])
            ->action(fn (EmployeeReferral $record, array $data) => self::perform(
                fn (ReferralService $s) => $s->setIncentiveEligibility($record, ! $record->incentive_eligible, $data['reason'], auth()->user()?->employee),
                'Referral bonus eligibility updated',
            ));
    }

    /**
     * @return array<int, string>
     */
    public static function recruiterOptions(): array
    {
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor(auth()->user());

        return Employee::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereKey($visibleIds))
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName()])
            ->all();
    }

    private static function can(EmployeeReferral $record, ReferralStatus $to): bool
    {
        return in_array($to, $record->status->manualTransitions(), true)
            && (bool) auth()->user()?->can('review', $record);
    }

    /**
     * @param  callable(ReferralService): mixed  $callback
     */
    private static function perform(callable $callback, string $success): void
    {
        InterviewsTable::guarded('Referral could not be updated', fn () => $callback(app(ReferralService::class)));

        Notification::make()->title($success)->success()->send();
    }
}
