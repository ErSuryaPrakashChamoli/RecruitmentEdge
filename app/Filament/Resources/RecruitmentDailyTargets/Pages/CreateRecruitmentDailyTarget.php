<?php

namespace App\Filament\Resources\RecruitmentDailyTargets\Pages;

use App\Filament\Concerns\GuardsDomainExceptionsOnSave;
use App\Filament\Resources\RecruitmentDailyTargets\RecruitmentDailyTargetResource;
use App\Models\User;
use App\Services\RecruitmentTargetService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The "exactly one scope" rule is enforced by RecruitmentDailyTargetForm validation and the
 * RecruitmentDailyTarget saving guard. Phase 8.9 (P89-SEC-001): the target is created by
 * RecruitmentTargetService, which authorizes its scope and records the creator.
 */
class CreateRecruitmentDailyTarget extends CreateRecord
{
    use GuardsDomainExceptionsOnSave;

    protected static string $resource = RecruitmentDailyTargetResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return static::guarded('Not saved', fn (): Model => app(RecruitmentTargetService::class)->create($user, $data));
    }
}
