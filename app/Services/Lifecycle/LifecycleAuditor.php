<?php

namespace App\Services\Lifecycle;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferRevisionStatus;
use App\Enums\OfferStatus;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.3: read-only lifecycle integrity checks behind `lifecycle:audit`. It never writes —
 * every check is a bounded SELECT, and it reports rather than repairs.
 *
 * - ERROR: inconsistent under any version of the schema (e.g. a joining without an application,
 *   an employee linked to a candidate who never joined, released terms that differ from the
 *   released revision, a reporting cycle).
 * - WARNING: states Phase 8.3 now prevents, which older data may legitimately contain because the
 *   rule (or the history that proves it) did not exist yet — flagged, never called corruption.
 * - INFO: context counts.
 *
 * Limitation: facts the schema never recorded (e.g. a requisition change made by a form edit before
 * Phase 8.3) cannot be detected; they are not fabricated.
 */
class LifecycleAuditor
{
    public const string ERROR = 'ERROR';

    public const string WARNING = 'WARNING';

    public const string INFO = 'INFO';

    private const string LEGACY = ' (may predate Phase 8.3)';

    /**
     * @return array<int, array{level: string, check: string, subject: string, detail: string}>
     */
    public function run(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $requisitionId = null, ?int $applicationId = null, int $limit = 50): array
    {
        $applications = fn (): Builder => CandidateApplication::withTrashed()
            ->when($requisitionId !== null, fn (Builder $query) => $query->where('requisition_id', $requisitionId))
            ->when($applicationId !== null, fn (Builder $query) => $query->whereKey($applicationId))
            ->when($from !== null, fn (Builder $query) => $query->where('updated_at', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('updated_at', '<=', $to));
        $scoped = fn (Builder $query, string $column = 'candidate_application_id'): Builder => $query->whereIn($column, $applications()->select('candidate_applications.id'));
        $closed = [ApplicationStatus::Rejected->value, ApplicationStatus::Dropout->value];
        $openInterview = [InterviewStatus::Completed->value, InterviewStatus::Cancelled->value, InterviewStatus::NoShow->value];
        $openOffer = [OfferStatus::Draft->value, OfferStatus::Initiated->value, OfferStatus::Released->value];
        $findings = [];
        $add = function (string $level, string $check, string $subject, string $detail) use (&$findings): void {
            $findings[] = compact('level', 'check', 'subject', 'detail');
        };

        // Joining records without an application.
        CandidateJoining::query()
            ->whereNotExists(fn ($q) => $q->from('candidate_applications')->whereColumn('candidate_applications.id', 'candidate_joinings.candidate_application_id')->whereNull('candidate_applications.deleted_at'))
            ->limit($limit)->pluck('id')
            ->each(fn (int $id) => $add(self::ERROR, 'joining_without_application', "JOIN-{$id}", 'The joining record has no (live) application.'));

        // Employees converted from a candidate who never joined.
        Employee::query()->whereNotNull('candidate_id')
            ->whereNotExists(fn ($q) => $q->from('candidate_joinings')
                ->join('candidate_applications', 'candidate_applications.id', '=', 'candidate_joinings.candidate_application_id')
                ->whereColumn('candidate_applications.candidate_id', 'employees.candidate_id')
                ->where('candidate_joinings.status', JoiningStatus::Joined->value))
            ->limit($limit)->get(['id', 'employee_code'])
            ->each(fn (Employee $employee) => $add(self::ERROR, 'employee_without_joined_joining', $employee->employee_code, 'The employee was converted from a candidate with no joining marked Joined.'));

        // Released terms that differ from the released revision (a post-release edit).
        OfferRevision::query()->where('status', OfferRevisionStatus::Released->value)
            ->whereIn('offer_id', $scoped(Offer::query())->select('id'))
            ->with('offer')->limit($limit)->get()
            ->each(function (OfferRevision $revision) use ($add): void {
                $changed = collect(OfferRevision::TERMS)->filter(fn (string $term) => (string) $revision->getRawOriginal($term) !== (string) $revision->offer->getRawOriginal($term))->values();

                if ($changed->isNotEmpty()) {
                    $add(self::ERROR, 'released_offer_changed', $revision->offer->offer_code, 'Terms differ from the released revision: '.$changed->implode(', ').'.');
                }
            });

        // Reporting cycles (the closure table lists someone as their own ancestor at depth > 0).
        DB::table('employee_hierarchy')->where('tenant_id', TenantContext::current()->requireId())->whereColumn('ancestor_id', 'descendant_id')->where('depth', '>', 0)->limit($limit)->pluck('ancestor_id')
            ->each(fn (int $id) => $add(self::ERROR, 'hierarchy_cycle', "EMP-{$id}", 'The employee appears in their own reporting line.'));

        // Open items left on closed applications.
        $scoped(Interview::query())->whereNotIn('status', $openInterview)
            ->whereIn('candidate_application_id', CandidateApplication::withTrashed()->whereIn('status', $closed)->select('id'))
            ->limit($limit)->pluck('id')
            ->each(fn (int $id) => $add(self::WARNING, 'open_interview_on_closed_application', "INT-{$id}", 'The interview is still open although its application was rejected or dropped out'.self::LEGACY.'.'));

        $scoped(Offer::query())->whereIn('status', $openOffer)
            ->whereIn('candidate_application_id', CandidateApplication::withTrashed()->whereIn('status', $closed)->select('id'))
            ->limit($limit)->pluck('offer_code')
            ->each(fn (string $code) => $add(self::WARNING, 'open_offer_on_closed_application', $code, 'The offer is still open although its application was rejected or dropped out'.self::LEGACY.'.'));

        $scoped(CandidateJoining::query())->whereIn('status', [JoiningStatus::Expected->value, JoiningStatus::Confirmed->value])
            ->whereIn('candidate_application_id', CandidateApplication::withTrashed()->whereIn('status', $closed)->select('id'))
            ->limit($limit)->pluck('id')
            ->each(fn (int $id) => $add(self::WARNING, 'pending_joining_on_closed_application', "JOIN-{$id}", 'The joining is still pending although its application was rejected or dropped out'.self::LEGACY.'.'));

        // More than one open offer on an application.
        $scoped(Offer::query())->whereIn('status', [...$openOffer, OfferStatus::Accepted->value])
            ->select('candidate_application_id')->groupBy('candidate_application_id')->havingRaw('count(*) > 1')->limit($limit)->pluck('candidate_application_id')
            ->each(fn (int $id) => $add(self::WARNING, 'multiple_open_offers', "APP-{$id}", 'More than one offer is open on this application'.self::LEGACY.'.'));

        // Accepted offers without a joining record.
        $scoped(Offer::query())->where('status', OfferStatus::Accepted->value)
            ->whereNotExists(fn ($q) => $q->from('candidate_joinings')->whereColumn('candidate_joinings.candidate_application_id', 'offers.candidate_application_id'))
            ->limit($limit)->pluck('offer_code')
            ->each(fn (string $code) => $add(self::WARNING, 'accepted_offer_without_joining', $code, 'The offer was accepted but the application has no joining record'.self::LEGACY.'.'));

        // Offers released or accepted on an application that never reached Selected.
        $scoped(Offer::query())->whereIn('status', [OfferStatus::Released->value, OfferStatus::Accepted->value])
            ->whereNotExists(fn ($q) => $q->from('candidate_stage_histories')->whereColumn('candidate_stage_histories.candidate_application_id', 'offers.candidate_application_id')
                ->whereIn('candidate_stage_histories.new_stage', $this->stagesFrom(CandidateStage::Selected)))
            ->limit($limit)->pluck('offer_code')
            ->each(fn (string $code) => $add(self::WARNING, 'offer_before_selection', $code, 'The offer went out without the application ever reaching Selected'.self::LEGACY.'.'));

        // Joined pipeline stage without a joined joining record (the anchor).
        $applications()->whereIn('current_stage', $this->stagesFrom(CandidateStage::Joined))
            ->whereNotExists(fn ($q) => $q->from('candidate_joinings')->whereColumn('candidate_joinings.candidate_application_id', 'candidate_applications.id')->where('candidate_joinings.status', JoiningStatus::Joined->value))
            ->limit($limit)->pluck('application_code')
            ->each(fn (string $code) => $add(self::WARNING, 'joined_stage_without_joining', $code, 'The application is at the Joined stage but no joining is marked Joined — it is not counted as a hire'.self::LEGACY.'.'));

        // Feedback attributed to someone other than the assigned interviewer.
        InterviewFeedback::query()->where('is_current', true)
            ->join('interviews', 'interviews.id', '=', 'interview_feedback.interview_id')
            ->whereColumn('interview_feedback.interviewer_id', '!=', 'interviews.interviewer_id')
            ->whereIn('interviews.candidate_application_id', $applications()->select('candidate_applications.id'))
            ->limit($limit)->pluck('interview_feedback.id')
            ->each(fn (int $id) => $add(self::WARNING, 'feedback_not_by_assigned_interviewer', "FEEDBACK-{$id}", 'The feedback is attributed to someone other than the interview\'s assigned interviewer'.self::LEGACY.'.'));

        $moves = AuditLog::query()->where('action', 'application_moved')->when($applicationId !== null, fn (Builder $query) => $query->where('auditable_id', $applicationId))->count();
        $add(self::INFO, 'application_moves', '—', "{$moves} explicit application move(s) recorded. Requisition changes made by ordinary edits before Phase 8.3 were never recorded and cannot be detected.");
        $cascades = AuditLog::query()->where('action', 'lifecycle_cascade')->when($applicationId !== null, fn (Builder $query) => $query->where('auditable_id', $applicationId))->count();
        $add(self::INFO, 'closure_cascades', '—', "{$cascades} application closure(s) closed their open interviews, offers and joining automatically.");

        return $findings;
    }

    /**
     * @return array<int, string>
     */
    private function stagesFrom(CandidateStage $stage): array
    {
        return collect(CandidateStage::cases())->filter(fn (CandidateStage $candidate) => $candidate->order() >= $stage->order())->map->value->values()->all();
    }
}
