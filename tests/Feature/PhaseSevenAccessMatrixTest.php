<?php

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Pre-Phase-8 freeze: who can open each Automation (Phase 6) and EDGE Intelligence (Phase 7) page.
 */
const PHASE_SEVEN_PAGES = [
    'intelligence' => '/admin/intelligence-overview',
    'risks' => '/admin/hiring-risks',
    'memory' => '/admin/hiring-memory-records',
    'rules' => '/admin/automation-rules',
    'executions' => '/admin/automation-executions',
    'automation dashboard' => '/admin/automation-dashboard',
    'action center' => '/admin/recruiter-actions',
    'notification center' => '/admin/notification-center',
];

test('guests are sent to login from every automation and intelligence page', function (string $path): void {
    get($path)->assertRedirect('/admin/login');
})->with(PHASE_SEVEN_PAGES);

test('each role reaches exactly the automation and intelligence pages its permissions allow', function (string $role, array $allowed): void {
    $this->seed(RolePermissionSeeder::class);
    actingAs(User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole($role));

    foreach (PHASE_SEVEN_PAGES as $page => $path) {
        expect(get($path)->status())->toBe(in_array($page, $allowed, true) ? 200 : 403, "{$role} → {$page}");
    }
})->with([
    'recruiter' => ['recruiter', ['intelligence', 'risks', 'action center', 'notification center']],
    'assistant manager' => ['assistant_manager', ['intelligence', 'risks', 'memory', 'rules', 'executions', 'automation dashboard', 'action center', 'notification center']],
    'manager' => ['manager', ['intelligence', 'risks', 'memory', 'rules', 'executions', 'automation dashboard', 'action center', 'notification center']],
    'vp hr' => ['vp_hr', ['intelligence', 'risks', 'memory', 'rules', 'executions', 'automation dashboard', 'action center', 'notification center']],
    'chro' => ['chro', ['intelligence', 'risks', 'memory', 'rules', 'executions', 'automation dashboard', 'action center', 'notification center']],
    'employee (own actions and notifications only)' => ['employee', ['action center', 'notification center']],
]);
