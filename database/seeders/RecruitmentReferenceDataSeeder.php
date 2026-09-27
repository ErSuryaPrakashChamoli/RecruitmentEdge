<?php

namespace Database\Seeders;

use App\Enums\CandidateStage;
use App\Enums\CommunicationChannel;
use App\Enums\OfferLetterTemplateFormat;
use App\Enums\RejectionCategory;
use App\Enums\StageRequirement;
use App\Enums\StageType;
use App\Enums\TemplateStatus;
use App\Models\CandidateSource;
use App\Models\CommunicationTemplate;
use App\Models\OfferLetterTemplate;
use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentSetting;
use App\Models\RecruitmentStage;
use App\Services\Communication\CommunicationTemplateService;
use App\Services\PipelineTemplateService;
use App\Services\StageConfigurationService;
use App\Services\StandardOfferLetterDocument;
use Illuminate\Database\Seeder;

/**
 * Seeds the candidate sources (Section 33), rejection/dropout reasons (Section 14), and default
 * recruitment settings (RecruitmentSetting::DEFINITIONS) named in the product spec, as editable
 * starting points from Administration. Idempotent. Phase 8.6 (D8.6-007): master data is matched by
 * code including archived rows, and an existing row is never restored or overwritten.
 */
class RecruitmentReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            'Naukri', 'Indeed', 'LinkedIn', 'Apna', 'WorkIndia', 'Website', 'Employee Referral',
            'Walk-in', 'WhatsApp', 'Facebook', 'Instagram', 'Agency', 'Internal Database', 'Other',
        ];

        foreach ($sources as $index => $name) {
            CandidateSource::withTrashed()->firstOrCreate(
                ['code' => 'SRC-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                ['name' => $name, 'is_active' => true],
            );
        }

        $reasons = [
            ['Not Interested', RejectionCategory::General],
            ['Salary Issue', RejectionCategory::General],
            ['Location Issue', RejectionCategory::General],
            ['Experience Mismatch', RejectionCategory::General],
            ['Qualification Mismatch', RejectionCategory::General],
            ['Notice Period', RejectionCategory::General],
            ['Selected Elsewhere', RejectionCategory::General],
            ['No Response', RejectionCategory::General],
            ['Interview Rejected', RejectionCategory::Interview],
            ['Offer Rejected', RejectionCategory::Offer],
            ['Did Not Join', RejectionCategory::Joining],
            ['Background Verification', RejectionCategory::Joining],
            ['Candidate Withdrew', RejectionCategory::General],
            ['Other', RejectionCategory::General],
        ];

        foreach ($reasons as $index => [$name, $category]) {
            RecruitmentRejectionReason::withTrashed()->firstOrCreate(
                ['code' => 'RSN-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                ['name' => $name, 'category' => $category, 'is_active' => true],
            );
        }

        // firstOrCreate, never updateOrCreate: re-running the seeder must not overwrite a value an
        // admin has already changed from Administration > Recruitment Settings.
        foreach (RecruitmentSetting::DEFINITIONS as $key => $definition) {
            RecruitmentSetting::query()->firstOrCreate(
                ['key' => $key],
                [
                    'value' => (string) $definition['default'],
                    'type' => $definition['type'],
                    'group' => $definition['group'],
                    'description' => $definition['description'],
                ],
            );
        }

        $this->seedHiringPipelines();
        $this->seedCommunicationTemplates();

        // The protected standard offer letter: a Word file admins can download, edit and upload again
        // from Administration > Offer Letter Templates, but never delete. It only becomes the default
        // while no other default exists, so an admin's own default template is never replaced.
        if (OfferLetterTemplate::systemTemplate() === null) {
            OfferLetterTemplate::query()->create([
                'name' => 'Standard Offer Letter',
                'format' => OfferLetterTemplateFormat::Word,
                'file_path' => app(StandardOfferLetterDocument::class)->store(),
                'is_system' => true,
                'is_active' => true,
                'is_default' => ! OfferLetterTemplate::query()->where('is_default', true)->exists(),
            ]);
        }
    }

    /**
     * Phase 4: the system stage library, the default "Standard Corporate Hiring" template, a few
     * custom stages and example templates as editable starting points. Idempotent — templates and
     * stages are matched by slug/code and never overwritten.
     */
    private function seedHiringPipelines(): void
    {
        $stageService = app(StageConfigurationService::class);
        $templateService = app(PipelineTemplateService::class);

        $system = $stageService->ensureSystemStages();
        $templateService->ensureDefaultTemplate();

        $custom = collect([
            ['name' => 'Technical Assessment', 'code' => 'technical_assessment', 'stage_type' => StageType::Assessment, 'milestone' => CandidateStage::Screened, 'sla_hours' => 72, 'requires_candidate_action' => true, 'requirements' => [StageRequirement::Email->value], 'candidate_label' => 'Assessment'],
            ['name' => 'Aptitude Test', 'code' => 'aptitude_test', 'stage_type' => StageType::Assessment, 'milestone' => CandidateStage::Screened, 'sla_hours' => 48, 'requires_candidate_action' => true, 'candidate_label' => 'Aptitude Test'],
            ['name' => 'Group Discussion', 'code' => 'group_discussion', 'stage_type' => StageType::Interview, 'milestone' => CandidateStage::Interview1, 'is_interview_stage' => true, 'candidate_label' => 'Group Discussion'],
        ])->mapWithKeys(fn (array $stage) => [
            $stage['code'] => RecruitmentStage::query()->where('code', $stage['code'])->first() ?? $stageService->create($stage),
        ]);

        $stages = $system->toBase()->merge($custom);

        $templates = [
            'High Volume Hiring' => ['sourced', 'contact_attempted', 'connected', 'interested', 'screened', 'shortlisted', 'interview_scheduled', 'interview_1', 'selected', 'offer_released', 'offer_accepted', 'joining_confirmed', 'joined'],
            'Technology Hiring' => ['sourced', 'connected', 'interested', 'screened', 'technical_assessment', 'shortlisted', 'interview_scheduled', 'interview_1', 'interview_2', 'final_interview', 'selected', 'offer_initiated', 'offer_released', 'offer_accepted', 'joining_confirmed', 'joined', 'documents_completed', 'onboarding_completed'],
            'Campus Hiring' => ['sourced', 'interested', 'aptitude_test', 'shortlisted', 'group_discussion', 'final_interview', 'selected', 'offer_released', 'offer_accepted', 'joined'],
            'Leadership Hiring' => ['sourced', 'connected', 'interested', 'screened', 'shortlisted', 'interview_scheduled', 'interview_1', 'interview_2', 'final_interview', 'selected', 'offer_initiated', 'offer_released', 'offer_accepted', 'joining_confirmed', 'joined', 'documents_completed', 'onboarding_completed'],
        ];

        foreach ($templates as $name => $codes) {
            if (RecruitmentPipelineTemplate::query()->where('name', $name)->exists()) {
                continue;
            }

            $templateService->create(['name' => $name], array_map(fn (string $code) => ['recruitment_stage_id' => $stages->get($code)->id], $codes));
        }
    }

    /**
     * Phase 5 starter templates, keyed by the purposes event-driven communications look up.
     * Email templates start Active (the app's own mail transport); SMS and WhatsApp start as
     * Draft, since they need a configured provider — and WhatsApp a provider-approved template
     * name — before an administrator activates them. Idempotent; never overwrites edits.
     */
    private function seedCommunicationTemplates(): void
    {
        $service = app(CommunicationTemplateService::class);

        $templates = [
            ['application_received', 'Application received', 'We received your application for {{requisition.title}}', "Hi {{candidate.first_name}},\n\nThank you for applying for {{requisition.title}} at {{company.name}}. Your reference is {{application.reference}}. We'll be in touch about next steps.\n\nYou can follow your application at {{links.portal}}"],
            ['interview_scheduled', 'Interview confirmation', 'Your interview for {{requisition.title}} on {{interview.date}}', "Hi {{candidate.first_name}},\n\nYour interview (round {{interview.round}}) for {{requisition.title}} is scheduled on {{interview.date}} at {{interview.time}}.\nWhere: {{interview.location}}\n\nPlease confirm your attendance in the candidate portal: {{links.portal}}"],
            ['interview_rescheduled', 'Interview rescheduled', 'Your interview has moved to {{interview.date}}', "Hi {{candidate.first_name}},\n\nYour interview for {{requisition.title}} has been rescheduled to {{interview.date}} at {{interview.time}}.\nWhere: {{interview.location}}"],
            ['interview_cancelled', 'Interview cancelled', 'Your interview on {{interview.date}} is cancelled', "Hi {{candidate.first_name}},\n\nYour interview for {{requisition.title}} on {{interview.date}} has been cancelled. Your recruiter {{recruiter.name}} will contact you about next steps."],
            ['interview_reminder', 'Interview reminder', 'Reminder: interview tomorrow at {{interview.time}}', "Hi {{candidate.first_name}},\n\nA reminder that your interview for {{requisition.title}} is on {{interview.date}} at {{interview.time}}.\nWhere: {{interview.location}}"],
            ['offer_released', 'Offer released', 'Your offer from {{company.name}}', "Hi {{candidate.first_name}},\n\nCongratulations! Your offer for {{requisition.title}} has been released. Your recruiter {{recruiter.name}} will share the details and next steps."],
            // Phase 6: used by the "Candidate No Response Follow-up" automation template (only
            // sent by an automation rule an administrator activates).
            ['candidate_checkin', 'Candidate check-in', 'Checking in about {{requisition.title}}', "Hi {{candidate.first_name}},\n\nWe wanted to check in about your application for {{requisition.title}} at {{company.name}}. Are you still interested? Just reply, or contact {{recruiter.name}}.\n\nYou can follow your application at {{links.portal}}"],
            ['joining_reminder', 'Joining reminder', 'See you on {{joining.date}}', "Hi {{candidate.first_name}},\n\nWe're looking forward to you joining {{company.name}} as {{requisition.title}} on {{joining.date}}. Contact {{recruiter.name}} if anything has changed."],
        ];

        foreach ($templates as [$key, $name, $subject, $body]) {
            if (! CommunicationTemplate::query()->where(['key' => $key, 'channel' => CommunicationChannel::Email, 'language' => 'en'])->exists()) {
                $service->create(['key' => $key, 'name' => $name, 'channel' => CommunicationChannel::Email, 'subject' => $subject, 'body' => $body, 'status' => TemplateStatus::Active]);
            }

            foreach ([CommunicationChannel::Sms, CommunicationChannel::WhatsApp] as $channel) {
                if (! CommunicationTemplate::query()->where(['key' => $key, 'channel' => $channel, 'language' => 'en'])->exists()) {
                    $service->create(['key' => $key, 'name' => "{$name} ({$channel->label()})", 'channel' => $channel, 'body' => preg_replace('/\s+/', ' ', $body), 'status' => TemplateStatus::Draft]);
                }
            }
        }
    }
}
