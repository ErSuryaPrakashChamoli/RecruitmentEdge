<?php

namespace App\Http\Controllers\Careers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Careers\ApplyRequest;
use App\Models\JobPosting;
use App\Services\Distribution\CareerApplicationService;
use App\Services\Distribution\RecruitmentCampaignService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The organisation's public career site (Phase 5). Shows only live postings (published, open
 * requisition, not past closing) and only public posting fields — never internal requisition
 * notes, approvers or salary unless the posting opts in. Applications go through
 * CareerApplicationService into the existing candidate pipeline. Campaign/source attribution comes
 * from ?campaign= and ?utm_source= on the posting link, kept in the session until the application.
 */
class CareerSiteController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        return view('careers.index', [
            'postings' => JobPosting::query()
                ->live()
                ->with('requisition.location', 'requisition.department')
                ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
                ->latest('published_at')
                ->paginate(20)
                ->withQueryString(),
            'search' => $search,
        ]);
    }

    public function show(Request $request, string $slug, RecruitmentCampaignService $campaigns): View
    {
        $posting = $this->livePosting($slug);

        if ($request->filled('campaign') && ($campaign = $campaigns->resolveTrackingCode((string) $request->query('campaign'))) !== null) {
            $request->session()->put("careers.attribution.{$posting->id}.campaign_id", $campaign->id);
        }

        if ($request->filled('utm_source')) {
            $request->session()->put("careers.attribution.{$posting->id}.source", mb_substr((string) $request->query('utm_source'), 0, 40));
        }

        return view('careers.show', ['posting' => $posting]);
    }

    public function apply(ApplyRequest $request, string $slug, CareerApplicationService $applications): RedirectResponse
    {
        $posting = $this->livePosting($slug);
        $attribution = $request->session()->get("careers.attribution.{$posting->id}", []);

        try {
            $result = $applications->apply(
                $posting,
                [...$request->safe()->except(['resume', 'privacy_consent', 'website']), 'consent_email' => $request->boolean('consent_email'), 'consent_whatsapp' => $request->boolean('consent_whatsapp')],
                $request->file('resume'),
                ['channel' => filled($attribution['source'] ?? null) ? 'career_site:'.$attribution['source'] : 'career_site', 'source' => $attribution['source'] ?? null, 'campaign_id' => $attribution['campaign_id'] ?? null],
            );
        } catch (DomainException $e) {
            return back()->withInput($request->except('resume'))->withErrors(['application' => $e->getMessage()]);
        }

        return redirect()->route('careers.applied', $posting->public_slug)->with('careers_reference', $result['application']->application_code)->with('careers_existing', $result['existing']);
    }

    public function applied(string $slug): View
    {
        return view('careers.applied', ['posting' => JobPosting::query()->where('public_slug', $slug)->firstOrFail()]);
    }

    public function feed(): Response
    {
        $postings = JobPosting::query()->live()->with('requisition.location', 'requisition.department')->latest('published_at')->limit(500)->get();

        return response()->view('careers.feed', ['postings' => $postings])->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function livePosting(string $slug): JobPosting
    {
        $posting = JobPosting::query()->where('public_slug', $slug)->with('requisition.location', 'requisition.department', 'requisition.designation')->firstOrFail();

        abort_unless($posting->isLive(), 404);

        return $posting;
    }
}
