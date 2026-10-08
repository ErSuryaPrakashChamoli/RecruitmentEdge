<?php

use App\Http\Controllers\PrivateFileController;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\StaffAccessService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * SEC-88-17 (owner decision 2026-10-01, A): private files (resumes, candidate documents) are no
 * longer served to anyone holding a signed URL. A preview link is short-lived, bound to the staff
 * user it was issued to, needs that user signed in with staff access, and every open is audited.
 */
test('as booted, previews of the private disk point at the protected route', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole('recruiter'), 'web');

    expect(Storage::disk('local')->temporaryUrl('resumes/cv.pdf', now()->addMinutes(30)))->toStartWith('/files/private?');
});

describe('a faked private disk', function (): void {
    beforeEach(function (): void {
        Storage::fake('local');
        PrivateFileController::registerTemporaryUrls();
        $this->seed(RolePermissionSeeder::class);

        $this->chroEmployee = Employee::factory()->create();
        $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
        $this->colleague = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->chroEmployee)->create()->id])->assignRole('vp_hr');

        Storage::disk('local')->put('candidate-documents/passport.pdf', '%PDF passport');
        $this->document = CandidateDocument::factory()->create([
            'candidate_id' => Candidate::factory()->create()->id,
            'candidate_joining_id' => null,
            'file_path' => 'candidate-documents/passport.pdf',
        ]);
    });

    function sec8817PreviewUrlFor(User $user): string
    {
        test()->actingAs($user, 'web');

        return Storage::disk('local')->temporaryUrl('candidate-documents/passport.pdf', now()->addMinutes(30));
    }

    test('the old open storage route is gone', function (): void {
        expect(Route::has('storage.local'))->toBeFalse();

        $this->get('/storage/candidate-documents/passport.pdf')->assertNotFound();
    });

    test('a preview link opens the file for the staff user it was issued to, sandboxed, and the open is audited', function (): void {
        $url = sec8817PreviewUrlFor($this->chro);

        expect($url)->toStartWith('/files/private?');

        $response = $this->actingAs($this->chro, 'web')->get($url);

        $response->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($response->streamedContent())->toBe('%PDF passport');

        $row = AuditLog::query()->where('action', 'private_file_downloaded')->sole();

        expect($row->auditable_type)->toBe($this->document->getMorphClass())
            ->and($row->auditable_id)->toBe($this->document->getKey())
            ->and($row->user_id)->toBe($this->chro->id);
    });

    test('the same link does not work for another staff user or for a guest', function (): void {
        $url = sec8817PreviewUrlFor($this->chro);

        $this->actingAs($this->colleague, 'web')->get($url)->assertForbidden();

        auth('web')->logout();
        $this->get($url)->assertRedirect();

        expect(AuditLog::query()->where('action', 'private_file_downloaded')->count())->toBe(0);
    });

    test('a link expires within minutes and cannot be pointed at another file', function (): void {
        Storage::disk('local')->put('candidate-documents/other.pdf', '%PDF other');
        $url = sec8817PreviewUrlFor($this->chro);

        $this->actingAs($this->chro, 'web')->get(str_replace('passport.pdf', 'other.pdf', $url))->assertForbidden();

        $this->travel(PrivateFileController::URL_TTL_MINUTES + 1)->minutes();
        $this->actingAs($this->chro, 'web')->get($url)->assertForbidden();
    });

    test('a suspended staff user cannot open a link issued to them', function (): void {
        $url = sec8817PreviewUrlFor($this->colleague);

        app(StaffAccessService::class)->suspend($this->colleague, $this->chro, 'Investigation');

        $response = $this->actingAs($this->colleague->fresh(), 'web')->get($url);

        expect($response->isSuccessful())->toBeFalse()
            ->and(AuditLog::query()->where('action', 'private_file_downloaded')->count())->toBe(0);
    });
});
