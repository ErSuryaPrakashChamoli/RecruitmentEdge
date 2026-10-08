<?php

use App\Enums\OfferStatus;
use App\Filament\Pages\IncentiveDashboard;
use App\Filament\Pages\RecruitmentReports;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ListRecruiterIncentiveCalculations;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ViewRecruiterIncentiveCalculation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\OfferLetterTemplate;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * SEC-88-13 (owner decision 2026-10-01, A): who took a copy of an offer letter, an incentive
 * statement or a report is recorded. Table exports and their downloads are covered in
 * SEC8803ExportGovernanceTest, private file opens in SEC8817PrivateFileAccessTest.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
    $this->employee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->employee->id])->assignRole('chro');
    $this->actingAs($this->chro, 'web');
});

function sec8813Audit(string $action): AuditLog
{
    return AuditLog::query()->where('action', $action)->sole();
}

test('an offer letter download is audited on the offer', function (): void {
    OfferLetterTemplate::factory()->default()->create();
    $offer = Offer::factory()->create(['status' => OfferStatus::Initiated]);

    Livewire::test(EditOffer::class, ['record' => $offer->getKey()])
        ->callAction('downloadOfferLetter')
        ->assertFileDownloaded("offer-letter-{$offer->offer_code}.pdf");

    $row = sec8813Audit('offer_letter_downloaded');

    expect($row->auditable_id)->toBe($offer->getKey())
        ->and($row->user_id)->toBe($this->chro->id)
        ->and($row->changes['issued'])->toBeFalse();
});

test('incentive statement downloads are audited: one calculation, another person\'s month, your own month', function (): void {
    $recruiter = Employee::factory()->create();
    $calculation = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $recruiter->id]);
    $month = now()->format('Y-m');

    Livewire::test(ViewRecruiterIncentiveCalculation::class, ['record' => $calculation->getKey()])
        ->callAction('downloadStatement')
        ->assertFileDownloaded();
    Livewire::test(ListRecruiterIncentiveCalculations::class)
        ->callAction('downloadPeriodStatement', data: ['employee_id' => $recruiter->id, 'month' => $month])
        ->assertFileDownloaded();
    Livewire::test(IncentiveDashboard::class)
        ->callAction('downloadStatement', data: ['month' => $month])
        ->assertFileDownloaded();

    $rows = AuditLog::query()->where('action', 'incentive_statement_downloaded')->orderBy('id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$this->chro->id])
        ->and([$rows[0]->auditable_id, $rows[0]->changes['statement']])->toBe([$calculation->getKey(), 'calculation'])
        ->and([$rows[1]->auditable_id, $rows[1]->changes['month']])->toBe([$recruiter->getKey(), $month])
        ->and([$rows[2]->auditable_id, $rows[2]->changes['month']])->toBe([$this->employee->getKey(), $month]);
});

test('a report CSV export is audited with the report and its row count', function (): void {
    Livewire::test(RecruitmentReports::class)->call('exportFunnel')->assertFileDownloaded('recruitment-funnel.csv');

    $row = sec8813Audit('report_exported');

    expect($row->user_id)->toBe($this->chro->id)
        ->and($row->changes['report'])->toBe('recruitment-funnel')
        ->and($row->changes['rows'])->toBeInt();
});
