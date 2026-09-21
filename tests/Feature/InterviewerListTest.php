<?php

use App\Filament\Resources\Interviewers\InterviewerResource;
use App\Filament\Resources\Interviewers\Pages\ManageInterviewers;
use App\Filament\Resources\Interviews\Pages\CreateInterview;
use App\Filament\Resources\Interviews\Pages\EditInterview;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

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

test('an administrator imports interviewers from an excel sheet by emp id', function (): void {
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
        ->assertNotified('2 interviewer(s) added');

    expect(Interviewer::query()->where('employee_id', $newEmployee->id)->sole()->is_active)->toBeTrue()
        ->and($inactive->refresh()->is_active)->toBeTrue()
        ->and(Interviewer::query()->count())->toBe(3);
});

test('a sheet without an emp id column is rejected', function (): void {
    actingAs($this->admin);

    Livewire::test(ManageInterviewers::class)
        ->callAction('importInterviewers', data: [
            'file' => interviewerSpreadsheetUpload([['Name', 'Designation'], ['Asha Rao', 'HR Manager']]),
        ])
        ->assertNotified('Import failed');

    expect(Interviewer::query()->count())->toBe(0);
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
