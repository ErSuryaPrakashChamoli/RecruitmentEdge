<?php

use App\Filament\Resources\Interviewers\InterviewerResource;
use App\Filament\Resources\Interviewers\Pages\ManageInterviewers;
use App\Filament\Resources\Interviews\Pages\CreateInterview;
use App\Filament\Resources\Interviews\Pages\EditInterview;
use App\Jobs\ImportInterviewersJob;
use App\Models\AuditLog;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\User;
use App\Notifications\StaffDatabaseNotification;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Notification::fake();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('chro');
});

/**
 * @param  array<int, array<int, string>>  $rows  Including the heading row.
 */
function interviewerSpreadsheetUpload(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows);

    $path = tempnam(sys_get_temp_dir(), 'interviewers');
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('interviewers.xlsx', (string) file_get_contents($path));
}

test('the interviewer dropdown lists only active interviewers with emp id and designation', function (): void {
    $listed = Employee::factory()->create([
        'first_name' => 'Asha',
        'last_name' => 'Rao',
        'employee_code' => 'EMP100',
        'designation_id' => Designation::factory()->create(['name' => 'HR Manager'])->id,
    ]);
    Interviewer::factory()->create(['employee_id' => $listed->id]);
    Interviewer::factory()->inactive()->create();
    Employee::factory()->create();

    actingAs($this->admin);

    Livewire::test(CreateInterview::class)
        ->assertFormFieldExists('interviewer_id', fn (Select $field): bool => $field->getOptions() === [$listed->id => 'Asha Rao (EMP100) — HR Manager']);
});

test('editing an interview keeps its current interviewer selectable after removal from the list', function (): void {
    $interview = Interview::factory()->create();

    actingAs($this->admin);

    Livewire::test(EditInterview::class, ['record' => $interview->getRouteKey()])
        ->assertFormFieldExists('interviewer_id', fn (Select $field): bool => array_key_exists($interview->interviewer_id, $field->getOptions()));
});

test('an administrator imports interviewers from an excel sheet by emp id, without reactivating deactivated ones', function (): void {
    $newEmployee = Employee::factory()->create(['employee_code' => 'EMP200']);
    $inactive = Interviewer::factory()->inactive()->create();
    $alreadyListed = Interviewer::factory()->create();

    actingAs($this->admin);

    Livewire::test(ManageInterviewers::class)
        ->callAction('importInterviewers', data: [
            'file' => interviewerSpreadsheetUpload([
                ['Emp ID', 'Name', 'Designation'],
                ['EMP200', 'New Person', 'Sales Head'],
                [$inactive->employee->employee_code, 'Returning Person', ''],
                [$alreadyListed->employee->employee_code, 'Listed Person', ''],
                ['UNKNOWN-1', 'Ghost', 'Nobody'],
            ]),
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Import started');

    // Phase 8.9 (P89-PERF-024): the import runs on the documents queue and reports by alert.
    Notification::assertSentTo($this->admin, StaffDatabaseNotification::class, fn (StaffDatabaseNotification $alert): bool => $alert->data['title'] === '[Interviewers] 1 interviewer(s) added');

    // Phase 8.6 (D8.6-009): a deactivated interviewer is reported, never silently reactivated.
    expect(Interviewer::query()->where('employee_id', $newEmployee->id)->sole()->is_active)->toBeTrue()
        ->and($inactive->refresh()->is_active)->toBeFalse()
        ->and(Interviewer::query()->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'interviewers_imported')->sole()->changes)
        ->toBe(['added' => 1, 'already_listed' => 1, 'inactive_not_reactivated' => 1, 'skipped' => 1]);
});

test('a sheet without an emp id column is rejected', function (): void {
    actingAs($this->admin);

    Livewire::test(ManageInterviewers::class)
        ->callAction('importInterviewers', data: [
            'file' => interviewerSpreadsheetUpload([['Name', 'Designation'], ['Asha Rao', 'HR Manager']]),
        ])
        ->assertNotified('Import started');

    expect(Interviewer::query()->count())->toBe(0);
    Notification::assertSentTo($this->admin, StaffDatabaseNotification::class, fn (StaffDatabaseNotification $alert): bool => $alert->data['title'] === '[Interviewers] Interviewer import failed');
});

test('an administrator can download the import template', function (): void {
    actingAs($this->admin);

    Livewire::test(ManageInterviewers::class)
        ->callAction('downloadTemplate')
        ->assertFileDownloaded('interviewers-template.xlsx');
});

test('users without settings.manage cannot open the interviewer list', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    actingAs($manager)
        ->get(InterviewerResource::getUrl('index'))
        ->assertForbidden();
});

test('a queued import does nothing for a requester who lost access, and still removes the upload', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('interviewer-imports/sheet.xlsx', (string) interviewerSpreadsheetUpload([['Emp ID'], [Employee::factory()->create()->employee_code]])->getContent());
    $this->admin->syncRoles([]);

    ImportInterviewersJob::dispatchSync('interviewer-imports/sheet.xlsx', $this->admin->id);

    expect(Interviewer::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists('interviewer-imports/sheet.xlsx'))->toBeFalse();
});

test('the interviewer import is queued on the documents queue with only ids in its payload', function (): void {
    Queue::fake();
    actingAs($this->admin);

    Livewire::test(ManageInterviewers::class)
        ->callAction('importInterviewers', data: ['file' => interviewerSpreadsheetUpload([['Emp ID'], ['EMP1']])]);

    Queue::assertPushedOn('documents', ImportInterviewersJob::class, fn (ImportInterviewersJob $job): bool => $job->userId === $this->admin->id && str_starts_with($job->storedPath, "tenants/{$this->tenant->id}/interviewer-imports/"));
    expect(Interviewer::query()->count())->toBe(0);
});
