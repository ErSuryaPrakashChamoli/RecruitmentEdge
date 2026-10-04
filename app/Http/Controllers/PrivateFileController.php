<?php

namespace App\Http\Controllers;

use App\Models\AiDocument;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\Import;
use App\Models\OfferLetter;
use App\Models\OfferLetterConversion;
use App\Models\OfferLetterTemplate;
use App\Models\OfferLetterTemplateVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
 *
 * SaaS-1: a valid signature is not enough. The URL also names the tenant it was issued in; the
 * download is served only when the signed-in person may still act in that tenant, the file belongs
 * to a record of that tenant (OWNERS — every private-file column), and the person may view that
 * record (its policy, or its parent's). Anything else is a 404.
 */
class PrivateFileController extends Controller
{
    public const string DISK = 'local';

    public const int URL_TTL_MINUTES = 5;

    /**
     * Every column that holds a private-disk file, with the ability that decides who may open it
     * (on the record itself, or on its parent relation). A referral's resume is stored on the
     * candidate the referral creates (candidates.resume_path).
     *
     * @var list<array{model: class-string<Model>, column: string, authorize?: string}>
     */
    public const array OWNERS = [
        ['model' => CandidateDocument::class, 'column' => 'file_path'],
        ['model' => Candidate::class, 'column' => 'resume_path'],
        ['model' => AiDocument::class, 'column' => 'file_path'],
        ['model' => OfferLetter::class, 'column' => 'file_path', 'authorize' => 'offer'],
        ['model' => OfferLetterConversion::class, 'column' => 'document_path', 'authorize' => 'offer'],
        ['model' => OfferLetterTemplate::class, 'column' => 'file_path'],
        ['model' => OfferLetterTemplateVersion::class, 'column' => 'file_path', 'authorize' => 'template'],
        ['model' => Import::class, 'column' => 'file_path', 'authorize' => 'user'],
    ];

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
            'tenant' => TenantContext::current()->id(),
        ], absolute: false);
    }

    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && (int) $request->query('user') === (int) $user->getKey(), 403);

        $tenant = Tenant::query()->find($request->query('tenant'));
        abort_unless($tenant instanceof Tenant && $user->canAccessTenant($tenant), 404);

        return TenantContext::current()->run($tenant, fn (): StreamedResponse => $this->serve($request, $user));
    }

    private function serve(Request $request, User $user): StreamedResponse
    {
        $path = (string) $request->query('path');
        $disk = Storage::disk(self::DISK);

        try {
            abort_unless($path !== '' && $disk->exists($path), 404);
        } catch (PathTraversalDetected) {
            abort(404);
        }

        $owner = $this->owningRecord($path);
        abort_unless($owner !== null && $this->mayOpen($user, $owner), 404);

        AuditLog::record($owner, 'private_file_downloaded', null, ['path' => $path]);

        return $disk->response($path, basename($path), [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The record of the current tenant the file belongs to (TenantScope limits every lookup), so
     * the download can be authorised and the audit row sits on it.
     */
    private function owningRecord(string $path): ?Model
    {
        foreach (self::OWNERS as $owner) {
            $query = $owner['model']::query();

            if (in_array(SoftDeletes::class, class_uses_recursive($owner['model']), true)) {
                $query->withTrashed();
            }

            if (($record = $query->where($owner['column'], $path)->first()) !== null) {
                return $record;
            }
        }

        return null;
    }

    private function mayOpen(User $user, Model $record): bool
    {
        $authorize = collect(self::OWNERS)->firstWhere('model', $record::class)['authorize'] ?? null;

        if ($authorize === 'user') {
            return (int) $record->getAttribute('user_id') === (int) $user->getKey();
        }

        $subject = $authorize === null ? $record : $record->{$authorize};

        return $subject instanceof Model && Gate::forUser($user)->allows('view', $subject);
    }
}
