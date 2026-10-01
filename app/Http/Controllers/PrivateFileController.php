<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\EmployeeReferral;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 8.8 (SEC-88-17): files on the private `local` disk (resumes, candidate and joining
 * documents, referral resumes) are no longer served to anyone holding a signed URL. Temporary URLs
 * for that disk point here instead: signed, short-lived, bound to the staff user who was shown the
 * file (the URL is only issued on a page that user may open), served only to that signed-in user
 * while staff access and MFA hold, and every open is audited.
 */
class PrivateFileController extends Controller
{
    public const string DISK = 'local';

    public const int URL_TTL_MINUTES = 5;

    /**
     * Points the private disk's temporary URLs (Filament file previews) at this route. Called from
     * AppServiceProvider; a test that fakes the disk calls it again for the fake. The adapter binds
     * the closure to itself, so the class is named explicitly (`self` would be the adapter and
     * recurse into its own temporaryUrl()).
     */
    public static function registerTemporaryUrls(): void
    {
        Storage::disk(self::DISK)->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expiration, array $options = []): string => PrivateFileController::temporaryUrl($path, $expiration, $options),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        $latest = now()->addMinutes(self::URL_TTL_MINUTES);
        $expiresAt = $expiration < $latest ? $expiration : $latest;

        return URL::temporarySignedRoute('files.private', $expiresAt, [
            'path' => $path,
            'user' => (int) auth('web')->id(),
        ], absolute: false);
    }

    public function __invoke(Request $request): StreamedResponse
    {
        abort_unless((int) $request->query('user') === (int) $request->user('web')?->getKey(), 403);

        $path = (string) $request->query('path');
        $disk = Storage::disk(self::DISK);

        try {
            abort_unless($path !== '' && $disk->exists($path), 404);
        } catch (PathTraversalDetected) {
            abort(404);
        }

        AuditLog::record($this->owningRecord($path) ?? $request->user('web'), 'private_file_downloaded', null, ['path' => $path]);

        return $disk->response($path, basename($path), [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The record the file belongs to, so the audit row sits on it.
     */
    private function owningRecord(string $path): ?Model
    {
        return CandidateDocument::query()->where('file_path', $path)->first()
            ?? Candidate::withTrashed()->where('resume_path', $path)->first()
            ?? EmployeeReferral::query()->where('resume_path', $path)->first();
    }
}
