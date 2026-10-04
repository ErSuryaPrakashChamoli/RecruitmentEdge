<?php

namespace Tests\Feature\Tenancy;

use App\Enums\AiDocumentStatus;
use App\Enums\JobPostingStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Models\AiConversation;
use App\Models\AiDocument;
use App\Models\AiDocumentChunk;
use App\Models\AiKnowledgeArticle;
use App\Models\AutomationRule;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateDocument;
use App\Models\CandidateJoining;
use App\Models\CandidatePortalAccount;
use App\Models\CandidateSource;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\JobPosting;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StaffDatabaseNotification;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantStorage;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * SaaS-1 cross-tenant fixtures: one fully populated tenant. Two of them (ALPHA and BRAVO) share the
 * same business codes on purpose (department ENG, candidate / requisition / offer codes), so a test
 * proves both per-tenant uniqueness and that nothing of one is reachable from the other. Every
 * human-readable value carries the tenant's marker, so any output can be scanned for a leak.
 */
final class TenantWorld
{
    public Tenant $tenant;

    public User $chro;

    public User $recruiter;

    public Employee $chroEmployee;

    public Employee $recruiterEmployee;

    public Department $department;

    public CandidateSource $source;

    public RecruitmentRequisition $requisition;

    public JobPosting $posting;

    public Candidate $candidate;

    public CandidateApplication $application;

    public Interview $interview;

    public Offer $offer;

    public CandidateJoining $joining;

    public CandidateDocument $document;

    public CandidatePortalAccount $portalAccount;

    public AiDocument $aiDocument;

    public AiKnowledgeArticle $article;

    public AiConversation $conversation;

    public AutomationRule $rule;

    /**
     * @param  string  $series  the number in every business code; the same in two worlds by default
     */
    public static function build(Tenant $tenant, string $marker, string $series = '500001'): self
    {
        $world = new self;
        $world->tenant = $tenant;

        TenantContext::current()->run($tenant, function () use ($world, $marker, $series): void {
            (new RolePermissionSeeder)->run();

            $world->department = Department::factory()->create(['name' => "{$marker} Engineering", 'code' => 'ENG']);
            $world->source = CandidateSource::factory()->create(['name' => "{$marker} Website", 'code' => CandidateSource::CODE_WEBSITE]);

            $world->chroEmployee = Employee::factory()->create(['first_name' => $marker, 'last_name' => 'Chief', 'employee_code' => 'EMP-'.$series, 'email' => strtolower($marker).'.chro@example.test', 'department_id' => $world->department->id]);
            $world->chro = User::factory()->create(['name' => "{$marker} Chief", 'email' => strtolower($marker).'.chro.login@example.test', 'employee_id' => $world->chroEmployee->id])->assignRole('chro');
            $world->recruiterEmployee = Employee::factory()->reportingTo($world->chroEmployee)->create(['first_name' => $marker, 'last_name' => 'Recruiter', 'employee_code' => 'EMP-'.str_pad((string) ((int) $series + 1), 6, '0', STR_PAD_LEFT), 'email' => strtolower($marker).'.recruiter@example.test', 'department_id' => $world->department->id]);
            $world->recruiter = User::factory()->create(['name' => "{$marker} Recruiter", 'email' => strtolower($marker).'.recruiter.login@example.test', 'employee_id' => $world->recruiterEmployee->id])->assignRole('recruiter');

            $world->requisition = RecruitmentRequisition::factory()->create(['code' => 'REQ-2026-'.$series, 'status' => RequisitionStatus::Open, 'department_id' => $world->department->id, 'manager_id' => $world->chroEmployee->id, 'created_by' => $world->chroEmployee->id]);
            $world->requisition->recruiters()->attach($world->recruiterEmployee->id);
            $world->posting = JobPosting::factory()->create(['requisition_id' => $world->requisition->id, 'title' => "{$marker} Field Sales Executive", 'public_slug' => 'field-sales-executive', 'status' => JobPostingStatus::Published, 'published_at' => now()->subDay()]);

            $world->candidate = Candidate::factory()->create(['candidate_code' => 'CAND-2026-'.$series, 'full_name' => "{$marker} Candidate", 'email' => 'shared.candidate@example.test', 'mobile' => '9876500001', 'source_id' => $world->source->id, 'created_by' => $world->recruiterEmployee->id]);
            $world->application = CandidateApplication::factory()->create(['application_code' => 'APP-2026-'.$series, 'candidate_id' => $world->candidate->id, 'requisition_id' => $world->requisition->id, 'recruiter_id' => $world->recruiterEmployee->id]);
            $world->interview = Interview::factory()->create(['candidate_application_id' => $world->application->id, 'interviewer_id' => $world->recruiterEmployee->id, 'remarks' => "{$marker} interview notes"]);
            $world->offer = Offer::factory()->create(['offer_code' => 'OFR-2026-'.$series, 'candidate_application_id' => $world->application->id, 'status' => OfferStatus::Accepted]);
            $world->joining = CandidateJoining::factory()->create(['candidate_application_id' => $world->application->id, 'offer_id' => $world->offer->id]);

            $path = TenantStorage::path("candidate-documents/{$world->candidate->id}").'/resume.pdf';
            Storage::disk('local')->put($path, "%PDF {$marker} resume");
            $world->document = CandidateDocument::factory()->create(['candidate_id' => $world->candidate->id, 'candidate_joining_id' => $world->joining->id, 'file_path' => $path]);
            $world->portalAccount = CandidatePortalAccount::factory()->create(['candidate_id' => $world->candidate->id, 'email' => 'shared.candidate@example.test', 'password' => 'Correct-Horse-Battery-9', 'password_set_at' => now()]);

            $world->aiDocument = AiDocument::factory()->create(['title' => "{$marker} Leave Policy", 'status' => AiDocumentStatus::Indexed, 'is_published' => true, 'privacy_declared_at' => now()]);
            AiDocumentChunk::factory()->create(['source_type' => 'document', 'source_id' => $world->aiDocument->id, 'content' => "{$marker} policy: twenty days of leave.", 'embedding' => [1.0]]);
            $world->article = AiKnowledgeArticle::factory()->create(['title' => "{$marker} Interview Guide", 'slug' => 'interview-guide', 'content' => "{$marker} guide: leave and interview process."]);
            AiDocumentChunk::factory()->create(['source_type' => 'knowledge_article', 'source_id' => $world->article->id, 'content' => "{$marker} guide: leave and interview process.", 'embedding' => [1.0]]);
            $world->conversation = AiConversation::factory()->create(['user_id' => $world->chro->id, 'title' => "{$marker} conversation"]);

            $world->rule = AutomationRule::factory()->create(['key' => 'interview_reminder', 'name' => "{$marker} interview reminder", 'owner_id' => $world->chro->id, 'created_by' => $world->chro->id]);

            RecruitmentSetting::put('sla_screening_hours', $marker === 'ALPHA' ? '24' : '72', 'int');

            $world->chro->notify(new StaffDatabaseNotification(Notification::make()->title("{$marker} alert")->getDatabaseMessage()));
        });

        return $world;
    }
}
