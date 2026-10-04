<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\Identity\InvitationRequiresSignIn;
use App\Services\Identity\InvitationUnavailable;
use App\Services\Identity\SessionRevocationService;
use App\Services\Identity\StaffAccessService;
use App\Services\Identity\TenantInvitationService;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS-2: opening and accepting a tenant invitation (staff session context).
 *
 * The tenant is always the invitation's own — found from the token's hash — never the URL, the
 * Host or anything else the request says. The token leaves the URL at once: the first request
 * keeps only its hash in the session and redirects to a token-less page (the token never reaches
 * a Referer header, the history of later pages or a log). Every way an invitation can be unusable
 * (unknown, revoked, expired, used, tenant unusable) gives the same answer.
 */
class TenantInvitationController extends Controller
{
    public const string SESSION_KEY = 'identity.invitation';

    public function __construct(private readonly TenantInvitationService $invitations) {}

    public function open(Request $request, string $token): Response
    {
        $invitation = $this->invitations->findByToken($token);

        if ($invitation === null || ! TenantContext::current()->run($invitation->tenant_id, fn (): bool => $this->invitations->isOpen($invitation))) {
            return $this->unavailable();
        }

        $request->session()->put(self::SESSION_KEY, TenantInvitationService::hash($token));

        return redirect()->route('invitations.show');
    }

    public function show(Request $request): Response
    {
        $invitation = $this->openInvitation($request);

        if ($invitation === null) {
            return $this->unavailable();
        }

        $user = $this->signedInUser($request);

        return response()->view('invitations.show', [
            'tenantName' => Tenant::query()->findOrFail($invitation->tenant_id)->name,
            'maskedEmail' => self::mask($invitation->email),
            'name' => $invitation->name,
            'signedIn' => $user !== null,
            'matches' => $user !== null && User::normaliseEmail($user->email) === $invitation->email,
            'loginUrl' => Filament::getPanel('admin')->getLoginUrl(),
        ]);
    }

    public function accept(Request $request): Response
    {
        $invitation = $this->openInvitation($request);

        if ($invitation === null) {
            return $this->unavailable();
        }

        $user = $this->signedInUser($request);

        if ($user === null) {
            // Back to this invitation once signed in (MFA challenge included).
            redirect()->setIntendedUrl(route('invitations.show'));

            return redirect(Filament::getPanel('admin')->getLoginUrl());
        }

        try {
            TenantContext::current()->run($invitation->tenant_id, fn () => $this->invitations->accept($invitation, $user));
        } catch (InvitationUnavailable $e) {
            return $e->reason === 'wrong_identity' || $e->reason === 'membership_suspended'
                ? redirect()->route('invitations.show')->withErrors(['invitation' => $e->getMessage()])
                : $this->unavailable();
        }

        $request->session()->forget(self::SESSION_KEY);
        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);

        return redirect(Filament::getPanel('admin')->getUrl($tenant));
    }

    public function register(Request $request): Response
    {
        $invitation = $this->openInvitation($request);

        if ($invitation === null) {
            return $this->unavailable();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        try {
            TenantContext::current()->run($invitation->tenant_id, fn () => $this->invitations->acceptAsNewIdentity($invitation, $data['name'], $data['password']));
        } catch (InvitationRequiresSignIn $e) {
            return redirect()->route('invitations.show')->withErrors(['invitation' => $e->getMessage()]);
        } catch (InvitationUnavailable) {
            return $this->unavailable();
        } catch (DomainException $e) {
            return redirect()->route('invitations.show')->withErrors(['password' => $e->getMessage()])->withInput($request->only('name'));
        }

        $request->session()->forget(self::SESSION_KEY);

        return redirect(Filament::getPanel('admin')->getLoginUrl())->with('status', 'Your account is ready. Sign in to continue.');
    }

    /**
     * The invitation whose link was opened in this session, while it can still be accepted.
     */
    private function openInvitation(Request $request): ?TenantInvitation
    {
        $hash = $request->session()->get(self::SESSION_KEY);
        $invitation = is_string($hash) ? $this->invitations->findByHash($hash) : null;

        if ($invitation === null || ! TenantContext::current()->run($invitation->tenant_id, fn (): bool => $this->invitations->isOpen($invitation))) {
            return null;
        }

        return $invitation;
    }

    /**
     * The signed-in staff identity, only while its session is still valid and the platform has not
     * disabled it.
     */
    private function signedInUser(Request $request): ?User
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            return null;
        }

        if (! app(SessionRevocationService::class)->isCurrent($request->session(), $user) || ! app(StaffAccessService::class)->identityPermits($user)) {
            Auth::guard('web')->logout();
            $request->session()->regenerate();

            return null;
        }

        return $user;
    }

    private function unavailable(): Response
    {
        return response()->view('invitations.unavailable', ['loginUrl' => Filament::getPanel('admin')->getLoginUrl()], 404);
    }

    /**
     * a••••@example.com — enough for the invited person to recognise the address, not to learn it.
     */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'••••@'.$domain;
    }
}
