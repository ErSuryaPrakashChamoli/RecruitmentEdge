<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * SEC-88-11 (owner decision 2026-10-01, A; approved scope): the candidate portal, the career site
 * and the staff sign-in / password-reset pages cannot be framed by another site, are not
 * content-sniffed, do not leak referrers, and pin HTTPS once served over it. No full CSP.
 */
dataset('sec8811 pages', [
    'portal sign-in' => '/portal/login',
    'portal password reset' => '/portal/password/forgot',
    'career site' => '/careers',
    'staff sign-in' => '/admin/login',
    'staff password reset' => '/admin/password-reset/request',
]);

test('the page carries the defensive headers', function (string $uri): void {
    $this->get($uri)
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');
})->with('sec8811 pages');

test('over HTTPS the page also pins HTTPS', function (string $uri): void {
    $this->get('https://localhost'.$uri)->assertHeader('Strict-Transport-Security', 'max-age=31536000');
})->with('sec8811 pages');

test('staff panel pages beyond sign-in are outside the approved scope and unchanged', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $staff = User::factory()->create()->assignRole('recruiter');

    $this->actingAs($staff, 'web')->get('/admin')->assertOk()->assertHeaderMissing('X-Frame-Options');
});
