<?php

use App\Enums\InterviewSlotStatus;
use App\Filament\Resources\InterviewAvailabilitySlots\InterviewAvailabilitySlotResource;
use App\Filament\Resources\InterviewAvailabilitySlots\Pages\CreateInterviewAvailabilitySlot;
use App\Filament\Resources\InterviewAvailabilitySlots\Pages\ListInterviewAvailabilitySlots;
use App\Models\Employee;
use App\Models\InterviewAvailabilitySlot;
use App\Models\Interviewer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function slotManager(string $role = 'manager', ?Employee $reportsTo = null): User
{
    $employee = $reportsTo !== null ? Employee::factory()->reportingTo($reportsTo)->create() : Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $user->assignRole($role);

    return $user;
}

test('publishing availability creates consecutive slots in the chosen timezone', function (): void {
    Carbon::setTestNow('2026-09-25 09:00:00');
    actingAs(slotManager('chro'));
    $interviewer = Interviewer::factory()->create();

    Livewire::test(CreateInterviewAvailabilitySlot::class)
        ->fillForm([
            'interviewer_id' => $interviewer->employee_id,
            'timezone' => 'Asia/Kolkata',
            'starts_at' => '2026-10-01 14:00:00',
            'duration_minutes' => 30,
            'count' => 2,
            'capacity' => 1,
            'mode' => 'video_call',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('2 slot(s) published');

    expect(InterviewAvailabilitySlot::query()->orderBy('starts_at')->pluck('starts_at')->map->toDateTimeString()->all())
        ->toBe(['2026-10-01 08:30:00', '2026-10-01 09:00:00']);
});

test('an overlapping slot is refused with a message', function (): void {
    Carbon::setTestNow('2026-09-25 09:00:00');
    actingAs(slotManager('chro'));
    $interviewer = Interviewer::factory()->create();
    $data = ['interviewer_id' => $interviewer->employee_id, 'timezone' => 'UTC', 'starts_at' => '2026-10-01 10:00:00', 'duration_minutes' => 60, 'count' => 1, 'capacity' => 1, 'mode' => 'video_call'];

    Livewire::test(CreateInterviewAvailabilitySlot::class)->fillForm($data)->call('create');
    Livewire::test(CreateInterviewAvailabilitySlot::class)->fillForm($data)->call('create')->assertNotified('Slots could not be published');

    expect(InterviewAvailabilitySlot::query()->count())->toBe(1);
});

test('slots are limited to the user\'s hierarchy', function (): void {
    $manager = slotManager();
    $teamInterviewer = Employee::factory()->reportingTo($manager->employee)->create();
    $mine = InterviewAvailabilitySlot::factory()->create(['interviewer_id' => $teamInterviewer->id]);
    $theirs = InterviewAvailabilitySlot::factory()->create();
    actingAs($manager);

    Livewire::test(ListInterviewAvailabilitySlots::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);
    $this->get(InterviewAvailabilitySlotResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
});

test('users without interview-slots.manage cannot open the slots page', function (): void {
    actingAs(slotManager('employee'));

    $this->get(InterviewAvailabilitySlotResource::getUrl('index'))->assertForbidden();
});

test('an open slot can be withdrawn with a reason', function (): void {
    actingAs(slotManager('chro'));
    $slot = InterviewAvailabilitySlot::factory()->create();

    Livewire::test(ListInterviewAvailabilitySlots::class)
        ->callAction(TestAction::make('cancelSlot')->table($slot), ['reason' => 'Interviewer on leave'])
        ->assertNotified('Slot withdrawn');

    expect($slot->fresh()->status)->toBe(InterviewSlotStatus::Cancelled)
        ->and($slot->fresh()->cancellation_reason)->toBe('Interviewer on leave');
});
