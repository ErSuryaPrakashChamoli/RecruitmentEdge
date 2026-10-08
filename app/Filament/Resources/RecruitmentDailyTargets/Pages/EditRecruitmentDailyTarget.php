<?php

namespace App\Filament\Resources\RecruitmentDailyTargets\Pages;

use App\Filament\Concerns\GuardsDomainExceptionsOnSave;
use App\Filament\Resources\RecruitmentDailyTargets\RecruitmentDailyTargetResource;
use App\Models\RecruitmentDailyTarget;
use App\Models\User;
use App\Services\RecruitmentTargetService;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The "exactly one scope" rule is enforced by RecruitmentDailyTargetForm validation and the
 * RecruitmentDailyTarget saving guard. Phase 8.9 (P89-SEC-001): saves and deletes go through
 * RecruitmentTargetService, which authorizes the target as it is and as it would become.
 */
class EditRecruitmentDailyTarget extends EditRecord
{
    use GuardsDomainExceptionsOnSave;

    protected static string $resource = RecruitmentDailyTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (RecruitmentDailyTarget $record): bool => app(RecruitmentTargetService::class)->delete($this->actor(), $record)),
        ];
    }

    /**
     * @param  RecruitmentDailyTarget  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return static::guarded('Not saved', fn (): Model => app(RecruitmentTargetService::class)->update($this->actor(), $record, $data));
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
