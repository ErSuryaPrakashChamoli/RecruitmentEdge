<?php

use App\Http\Session\StaffDatabaseSessionHandler;
use App\Models\CandidatePortalAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.9 (P89-SEC-007): staff and candidate sessions share the `sessions` table; only staff
 * sessions record their user, so revoking a staff user's sessions (by user_id) can never end a
 * candidate's session, and session counts in the Access Review are staff sessions.
 */
test('a staff session records its user; a candidate session records none', function (): void {
    config(['session.driver' => 'database']);
    $handler = app('session')->driver('database')->getHandler();
    $staff = User::factory()->create();
    $account = CandidatePortalAccount::factory()->create();

    Auth::shouldUse('web');
    auth('web')->setUser($staff);
    $handler->write('p89-staff-session', 'a:0:{}');

    Auth::shouldUse('candidate');
    auth('candidate')->setUser($account);
    (new StaffDatabaseSessionHandler(DB::connection(), 'sessions', 120, app()))->write('p89-candidate-session', 'a:0:{}');

    expect($handler)->toBeInstanceOf(StaffDatabaseSessionHandler::class)
        ->and(DB::table('sessions')->where('id', 'p89-staff-session')->value('user_id'))->toBe($staff->id)
        ->and(DB::table('sessions')->where('id', 'p89-candidate-session')->value('user_id'))->toBeNull()
        ->and(DB::table('sessions')->where('id', 'p89-candidate-session')->exists())->toBeTrue();
});
