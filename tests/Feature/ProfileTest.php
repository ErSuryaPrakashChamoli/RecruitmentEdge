<?php

use App\Filament\Pages\Profile;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the profile page shows the employee\'s HR-managed details', function (): void {
    $manager = Employee::factory()->create(['first_name' => 'Root', 'last_name' => 'Manager']);
    $employee = Employee::factory()->reportingTo($manager)->create([
        'employee_code' => 'EMP-000123',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'category' => 'Full-Time',
        'level' => 'L3',
    ]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $user->assignRole('recruiter');

    actingAs($user)
        ->get('/admin/profile')
        ->assertSuccessful()
        ->assertSee('EMP-000123')
        ->assertSee('Jane Doe')
        ->assertSee($employee->department->name)
        ->assertSee($employee->designation->name)
        ->assertSee('Root Manager')
        ->assertSee('Full-Time')
        ->assertSee('L3');
});

test('a user without a linked employee record does not see the employee details section', function (): void {
    $user = User::factory()->create(['employee_id' => null]);
    $user->assignRole('recruiter');
    actingAs($user);

    Livewire::test(Profile::class)
        ->assertFormFieldHidden('category_display')
        ->assertFormFieldHidden('photo');
});

test('a user can update their profile photo, which persists to their employee record', function (): void {
    Storage::fake('public');

    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    $file = UploadedFile::fake()->image('avatar.jpg');

    Livewire::test(Profile::class)
        ->fillForm(['photo' => $file])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(Profile::getUrl());

    $path = $employee->fresh()->photo_path;

    expect($path)->not->toBeNull();
    Storage::disk('public')->assertExists($path);
});
