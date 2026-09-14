<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Actions;

use App\Enums\RequisitionStatus;
use App\Models\RecruitmentRequisition;
use App\Services\RequisitionApprovalService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Dedicated lifecycle actions (Submit, Approve, Send Back, Open, Hold, Resume, Close, Cancel) shared
 * by the requisitions table and the edit page header. Each is only visible when the requisition is
 * in the right status AND the user holds the matching permission; RequisitionApprovalService still
 * re-checks both server-side, so a hidden action can never be forced through.
 */
class RequisitionLifecycleActions
{
    /**
     * @return array<int, Action>
     */
    public static function make(): array
    {
        return [
            self::transition('submitForApproval', 'Submit for Approval', RequisitionStatus::Draft, RequisitionStatus::PendingApproval, 'heroicon-o-paper-airplane', 'primary', 'update'),
            self::transition('approve', 'Approve', RequisitionStatus::PendingApproval, RequisitionStatus::Approved, 'heroicon-o-check-badge', 'success', 'approve'),
            self::transition('sendBackToDraft', 'Send Back to Draft', RequisitionStatus::PendingApproval, RequisitionStatus::Draft, 'heroicon-o-arrow-uturn-left', 'warning', 'approve', 'Remarks'),
            self::transition('open', 'Open', RequisitionStatus::Approved, RequisitionStatus::Open, 'heroicon-o-lock-open', 'success', 'update'),
            self::transition('hold', 'Put On Hold', RequisitionStatus::Open, RequisitionStatus::OnHold, 'heroicon-o-pause-circle', 'warning', 'update', 'Reason'),
            self::transition('resume', 'Resume', RequisitionStatus::OnHold, RequisitionStatus::Open, 'heroicon-o-play-circle', 'success', 'update'),
            self::transition('close', 'Close', [RequisitionStatus::Open, RequisitionStatus::OnHold], RequisitionStatus::Closed, 'heroicon-o-lock-closed', 'gray', 'update', 'Reason'),
            self::transition('cancel', 'Cancel Requisition', [RequisitionStatus::Draft, RequisitionStatus::PendingApproval, RequisitionStatus::Approved, RequisitionStatus::Open, RequisitionStatus::OnHold], RequisitionStatus::Cancelled, 'heroicon-o-x-circle', 'danger', 'update', 'Reason'),
        ];
    }

    public static function group(): ActionGroup
    {
        return ActionGroup::make(self::make())
            ->label('Status')
            ->icon('heroicon-o-arrow-path')
            ->button()
            ->color('gray');
    }

    /**
     * @param  RequisitionStatus|array<int, RequisitionStatus>  $from
     * @param  string|null  $remarksLabel  When set, a required remarks field is shown; otherwise remarks are optional.
     */
    private static function transition(
        string $name,
        string $label,
        RequisitionStatus|array $from,
        RequisitionStatus $to,
        string $icon,
        string $color,
        string $ability,
        ?string $remarksLabel = null,
    ): Action {
        $fromStatuses = is_array($from) ? $from : [$from];

        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (RecruitmentRequisition $record): bool => in_array($record->status, $fromStatuses, true)
                && ! $record->trashed()
                && (bool) auth()->user()?->can($ability, $record))
            ->modalHeading("{$label}: requisition")
            ->modalSubmitActionLabel($label)
            ->schema([
                Textarea::make('remarks')
                    ->label($remarksLabel ?? 'Remarks')
                    ->required($remarksLabel !== null)
                    ->rows(3),
            ])
            ->action(fn (RecruitmentRequisition $record, array $data) => self::perform($record, $to, $data));
    }

    /**
     * RequisitionApprovalService enforces the transition allow-list, the approval permission and
     * required reasons for every write path; surface its DomainException as a notification and
     * halt instead of letting it render a 500 page.
     *
     * @param  array<string, mixed>  $data
     */
    public static function perform(RecruitmentRequisition $record, RequisitionStatus $to, array $data): void
    {
        try {
            app(RequisitionApprovalService::class)->moveTo(
                $record,
                $to,
                auth()->user()?->employee,
                filled($data['remarks'] ?? null) ? $data['remarks'] : null,
            );
        } catch (DomainException $e) {
            Notification::make()
                ->title('Requisition status could not be changed')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }

        Notification::make()->title("Requisition moved to {$to->label()}")->success()->send();
    }
}
