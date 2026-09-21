<?php

namespace Database\Seeders\Demo;

use App\Enums\AiConversationStatus;
use App\Enums\AiDocumentStatus;
use App\Enums\AiMessageRole;
use App\Enums\AiRiskLevel;
use App\Enums\AiToolCallStatus;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\EmployeeStatus;
use App\Enums\FollowupStatus;
use App\Enums\InterviewStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Models\AiActionLog;
use App\Models\AiConversation;
use App\Models\AiDocument;
use App\Models\AiKnowledgeArticle;
use App\Models\CandidateApplication;
use App\Models\CandidateStageHistory;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRequisition;
use App\Services\PerformanceEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The AI Copilot's side of the demo: the knowledge base, two uploaded documents, Copilot
 * conversations whose answers are computed from the seeded data (so they match what the dashboards
 * show), one action waiting for approval, and a month of usage and action logs.
 */
final class DemoAiWorkspace
{
    private const string MODEL = 'gemini-3.6-flash';

    public function __construct(private readonly DemoContext $ctx) {}

    public function build(): void
    {
        try {
            $this->knowledgeBase();
            $this->documents();
            $this->conversations();
            $this->usageLogs();
            $this->actionLogs();
        } finally {
            DemoContext::freeze(null);
            $this->ctx->actAs(null);
        }
    }

    private function knowledgeBase(): void
    {
        DemoContext::freeze($this->ctx->storyStart->subDays(20)->setTime(11, 0));
        $chro = $this->ctx->person('chro');
        $this->ctx->actAs($chro);

        // Without model events: saving would index each article through the AI provider inline.
        // DemoSeeder queues the indexing for the whole knowledge base once the story is seeded.
        AiKnowledgeArticle::withoutEvents(function () use ($chro): void {
            foreach (DemoCatalog::KNOWLEDGE_ARTICLES as $title => [$category, $content]) {
                AiKnowledgeArticle::query()->create([
                    'title' => $title,
                    'slug' => Str::slug($title),
                    'category' => $category,
                    'content' => $content,
                    'is_published' => true,
                    'created_by' => $chro->id,
                ]);
            }
        });
    }

    private function documents(): void
    {
        DemoContext::freeze($this->ctx->storyStart->subDays(15)->setTime(15, 0));
        $chro = $this->ctx->person('chro');

        $documents = [
            'Recruitment SLA Handbook' => ['policy', <<<'TEXT'
Recruitment SLA Handbook

Stage turnaround targets (working days):
- Application to screening: 2
- Shortlist to interview line-up: 2
- Line-up to interview: 3
- Interview to selection decision: 3
- Selection to offer: 3
- Offer to acceptance: 5
- Selection to joining: 30
Overall time-to-hire target: 30 days from the candidate's application.

Escalation: a requisition open for more than 45 days with less than two active candidates per remaining opening is marked critical on the Position Health report and reviewed in the weekly TA meeting.
TEXT],
            'Interview Question Bank — Sales Roles' => ['interviews', <<<'TEXT'
Interview Question Bank — Sales Roles

HR round
1. Walk me through your current role and your monthly targets.
2. Tell me about a month you missed target. What did you change?
3. Why are you looking for a change now?

Sales round
1. Role-play: sell our product to a price-sensitive customer.
2. How do you plan your day to hit 40 calls and 4 meetings?
3. How do you handle a customer who says "I will think about it"?

What good looks like: clear numbers, ownership of misses, structured objection handling.
TEXT],
        ];

        foreach ($documents as $title => [$category, $content]) {
            $path = 'ai-documents/demo-'.Str::slug($title).'.txt';
            Storage::disk('local')->put($path, $content);

            AiDocument::withoutEvents(fn () => AiDocument::query()->create([
                'title' => $title,
                'category' => $category,
                'disk' => 'local',
                'file_path' => $path,
                'mime_type' => 'text/plain',
                'uploaded_by' => $chro->id,
                'is_published' => true,
                'status' => AiDocumentStatus::Pending,
            ]));
        }
    }

    private function conversations(): void
    {
        $today = $this->ctx->today;

        $this->conversation('chro', 'Positions at risk', $today->subDays(6)->setTime(10, 12), [
            [
                'question' => 'Which positions are at risk of missing their hiring date?',
                'tool' => ['find_at_risk_requisitions', [], AiRiskLevel::Read],
                'answer' => $this->atRiskAnswer(),
            ],
        ]);

        $this->conversation('chro', 'Last month\'s hiring funnel', $today->subDays(3)->setTime(9, 40), [
            [
                'question' => 'Summarise last month\'s hiring funnel and where we lost the most candidates.',
                'tool' => ['analyze_funnel', ['period' => 'last_month'], AiRiskLevel::Read],
                'answer' => $this->funnelAnswer(),
            ],
            [
                'question' => 'What should we do about the drop-off after first contact?',
                'answer' => "Most of the loss after first contact is candidates we never reach or who are not interested once we speak to them. Three things usually move this number:\n\n1. **Call within 2 hours of sourcing** — response rates fall sharply after the first day.\n2. **Share the JD and salary band on WhatsApp before the call**, so the conversation starts with an informed candidate.\n3. **Retire low-yield sources** — compare connect rates by source on the Source Performance widget and shift budget towards the top two.",
            ],
        ]);

        $this->conversation('chro', 'JD — Senior Laravel Developer', $today->subDays(12)->setTime(16, 5), [
            [
                'question' => 'Create a JD for Senior Laravel Developer.',
                'tool' => ['generate_jd', ['title' => 'Senior Laravel Developer', 'location' => 'Bengaluru'], AiRiskLevel::Recommend],
                'answer' => "## Senior Laravel Developer — Bengaluru (Hybrid)\n\n**About the role**\nYou will design and build the APIs and back-office tools that power our retail and support operations.\n\n**What you will do**\n- Build and maintain Laravel services, queues and REST APIs\n- Own features end to end — design, code review, testing and release\n- Improve performance of MySQL queries and Redis caching\n- Mentor two junior developers\n\n**What you bring**\n- 4–8 years of PHP, including 3+ years of Laravel\n- Strong MySQL, REST API design and automated testing\n- Experience with AWS, queues and Redis\n\n**Compensation:** ₹12–20 LPA, based on experience.",
            ],
        ]);

        $this->conversation('vp_hr', 'Recruiters below target', $today->subDays(1)->setTime(18, 20), [
            [
                'question' => 'Which recruiters are below target this month?',
                'tool' => ['compare_recruiters', ['period' => 'this_month'], AiRiskLevel::Read],
                'answer' => $this->recruitersAnswer(),
            ],
        ]);

        $this->conversation('r_priya', 'My plan for today', $today->setTime(9, 45)->min($today), [
            [
                'question' => 'What should I work on today?',
                'tool' => ['generate_dashboard_insights', [], AiRiskLevel::Read],
                'answer' => $this->recruiterDayAnswer('r_priya'),
            ],
        ]);

        // A proposed action waiting for approval, in the CHRO's own chat — she can approve it live.
        $this->conversation('chro', 'Interview scheduling', $today->subHours(2)->max($today->startOfDay()), $this->pendingInterviewTurn());
    }

    /**
     * @param  list<array{question: string, answer?: string, tool?: array{0: string, 1: array<string, mixed>, 2: AiRiskLevel}, pending?: bool}>  $turns
     */
    private function conversation(string $person, string $title, CarbonImmutable $at, array $turns): void
    {
        $user = $this->ctx->person($person)->user;

        if ($user === null || $turns === []) {
            return;
        }

        DemoContext::freeze($at);
        $this->ctx->actAs($this->ctx->person($person));

        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'title' => $title,
            'model' => self::MODEL,
            'status' => AiConversationStatus::Active,
            'last_message_at' => $at,
        ]);

        $moment = $at;

        foreach ($turns as $turn) {
            DemoContext::freeze($moment);
            $conversation->messages()->create(['role' => AiMessageRole::User, 'content' => $turn['question']]);

            if (isset($turn['tool'])) {
                [$toolName, $arguments, $risk] = $turn['tool'];
                $pending = $turn['pending'] ?? false;
                $callId = 'call_'.Str::lower(Str::random(12));
                $moment = $moment->addSeconds($this->ctx->number(3, 8));
                DemoContext::freeze($moment);

                $message = $conversation->messages()->create([
                    'role' => AiMessageRole::Assistant,
                    'tool_calls' => [['id' => $callId, 'name' => $toolName, 'arguments' => $arguments]],
                    ...$this->tokens(),
                ]);

                $toolCall = $message->toolCalls()->create([
                    'tool_name' => $toolName,
                    'provider_call_id' => $callId,
                    'arguments' => $arguments,
                    'risk_level' => $risk,
                    'status' => $pending ? AiToolCallStatus::Pending : AiToolCallStatus::Executed,
                    'requires_confirmation' => $pending,
                    'executed_at' => $pending ? null : $moment,
                ]);

                if (! $pending) {
                    $summary = Str::limit(strip_tags(str_replace(["\n", '#', '*', '|'], ' ', (string) ($turn['answer'] ?? ''))), 160);
                    $toolCall->result()->create(['output' => ['summary' => trim((string) preg_replace('/\s+/', ' ', $summary))], 'success' => true]);
                    $conversation->messages()->create([
                        'role' => AiMessageRole::Tool,
                        'content' => json_encode(['summary' => 'Tool completed'], JSON_THROW_ON_ERROR),
                        'tool_call_id' => $callId,
                        'tool_name' => $toolName,
                    ]);
                }
            }

            if (isset($turn['answer'])) {
                $moment = $moment->addSeconds($this->ctx->number(4, 12));
                DemoContext::freeze($moment);
                $conversation->messages()->create(['role' => AiMessageRole::Assistant, 'content' => $turn['answer'], ...$this->tokens()]);
            }

            $moment = $moment->addMinutes($this->ctx->number(1, 4));
        }

        $conversation->update(['last_message_at' => $moment]);
    }

    /**
     * @return array{input_tokens: int, output_tokens: int, cached_tokens: int, cost: float}
     */
    private function tokens(): array
    {
        $input = $this->ctx->number(900, 4200);
        $output = $this->ctx->number(120, 900);

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cached_tokens' => $this->ctx->number(0, (int) ($input / 2)),
            'cost' => round(($input * 0.3 + $output * 2.5) / 1_000_000, 6),
        ];
    }

    private function atRiskAnswer(): string
    {
        $rows = RecruitmentRequisition::query()
            ->where('status', RequisitionStatus::Open)
            ->with(['designation', 'location'])
            ->get()
            ->map(fn (RecruitmentRequisition $requisition): array => [
                'requisition' => $requisition,
                'days' => (int) abs($requisition->opening_date->diffInDays($this->ctx->today)),
                'remaining' => $requisition->remainingOpenings(),
                'pipeline' => $requisition->applications()->where('status', ApplicationStatus::Active)->whereIn('current_stage', $this->openPipelineStages())->count(),
            ])
            ->filter(fn (array $row): bool => $row['remaining'] > 0)
            ->sortByDesc(fn (array $row): float => $row['days'] * $row['remaining'] / max(1, $row['pipeline']))
            ->take(3);

        if ($rows->isEmpty()) {
            return 'No open requisition is currently at risk — every open position has a healthy pipeline.';
        }

        $list = $rows->map(fn (array $row): string => sprintf(
            '- **%s — %s, %s:** open %d days, %d openings left, %d candidates in the active pipeline',
            $row['requisition']->code,
            $row['requisition']->designation?->name,
            $row['requisition']->location?->city,
            $row['days'],
            $row['remaining'],
            $row['pipeline'],
        ))->implode("\n");

        return "These are the three positions most at risk, weighing how long they have been open against how many openings are left and how thin the active pipeline is:\n\n{$list}\n\n**Suggested next step:** add a second recruiter or a sourcing campaign to the first one, and review the salary band with the hiring manager if screening drop-offs stay high.";
    }

    private function funnelAnswer(): string
    {
        $start = $this->ctx->today->subMonthNoOverflow()->startOfMonth();
        $end = $start->endOfMonth();
        $reached = fn (CandidateStage $stage): int => CandidateStageHistory::query()
            ->where('new_stage', $stage)
            ->whereColumn('new_stage', '!=', 'previous_stage')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $sourced = CandidateApplication::query()->whereBetween('created_at', [$start, $end])->count();
        $interested = $reached(CandidateStage::Interested);
        $shortlisted = $reached(CandidateStage::Shortlisted);
        $interviewed = $reached(CandidateStage::Interview1);
        $selected = $reached(CandidateStage::Selected);
        $accepted = $reached(CandidateStage::OfferAccepted);
        $joined = $reached(CandidateStage::Joined);
        $percent = fn (int $part, int $whole): string => $whole > 0 ? round($part / $whole * 100).'%' : '—';

        return "**{$start->format('F Y')} funnel** (conversion from the previous stage):\n\n- **Sourced:** {$sourced}\n- **Interested:** {$interested} ({$percent($interested, $sourced)})\n- **Shortlisted:** {$shortlisted} ({$percent($shortlisted, $interested)})\n- **Cleared interview round 1:** {$interviewed} ({$percent($interviewed, $shortlisted)})\n- **Selected:** {$selected} ({$percent($selected, $interviewed)})\n- **Offer accepted:** {$accepted} ({$percent($accepted, $selected)})\n- **Joined:** {$joined}\n\nThe biggest loss is between **sourcing and interest** — candidates we could not reach or who were not interested once we spoke to them. Interview conversion is healthy.";
    }

    private function recruitersAnswer(): string
    {
        $engine = app(PerformanceEngine::class);
        $start = $this->ctx->today->startOfMonth();

        $scores = Employee::query()
            ->where('status', EmployeeStatus::Active)
            ->where('designation_id', $this->ctx->designations['DSG-RCT']->id)
            ->get()
            ->map(fn (Employee $recruiter): array => ['name' => $recruiter->fullName(), 'score' => $engine->compositeScoreFor($recruiter, $start, $this->ctx->today)])
            ->filter(fn (array $row): bool => $row['score'] !== null)
            ->sortBy('score')
            ->values();

        if ($scores->isEmpty()) {
            return 'There is not enough activity this month yet to score recruiters.';
        }

        $below = $scores->filter(fn (array $row): bool => $row['score'] < 70)->take(3);
        $list = ($below->isNotEmpty() ? $below : $scores->take(2))
            ->map(fn (array $row): string => "- **{$row['name']}** — composite score {$row['score']}%")
            ->implode("\n");
        $best = $scores->last();

        return "Month-to-date composite performance (weighted achievement against targets):\n\n{$list}\n\nTop performer so far: **{$best['name']}** at {$best['score']}%. The gap for the recruiters above is mostly in connected calls and shortlists — worth a quick pipeline review with their assistant managers this week.";
    }

    private function recruiterDayAnswer(string $person): string
    {
        $recruiter = $this->ctx->person($person);
        $today = $this->ctx->today;

        $overdue = RecruitmentFollowup::query()->where('recruiter_id', $recruiter->id)->where('status', FollowupStatus::Pending)->where('followup_date', '<', $today->startOfDay())->count();
        $dueToday = RecruitmentFollowup::query()->where('recruiter_id', $recruiter->id)->where('status', FollowupStatus::Pending)->whereBetween('followup_date', [$today->startOfDay(), $today->endOfDay()])->count();
        $interviews = Interview::query()
            ->whereHas('candidateApplication', fn ($query) => $query->where('recruiter_id', $recruiter->id))
            ->whereIn('status', [InterviewStatus::Scheduled, InterviewStatus::Confirmed, InterviewStatus::Rescheduled])
            ->whereBetween('scheduled_at', [$today->startOfDay(), $today->addDay()->endOfDay()])
            ->count();
        $offers = Offer::query()
            ->whereHas('candidateApplication', fn ($query) => $query->where('recruiter_id', $recruiter->id))
            ->where('status', OfferStatus::Released)
            ->count();

        $count = fn (int $n, string $noun): string => $n.' '.Str::plural($noun, $n);

        return "Good morning! Here is your plan for today:\n\n1. **{$count($overdue, 'overdue follow-up')}** — clear these first; they are the candidates most likely to go cold.\n2. **{$count($dueToday, 'follow-up')} due today.**\n3. **{$count($interviews, 'interview')} today or tomorrow** — confirm attendance with a WhatsApp reminder.\n4. **{$count($offers, 'released offer')} awaiting a decision** — a quick call usually closes them.\n\nTip: your connected-call rate is best between 11 am and 1 pm.";
    }

    /**
     * An interview the Copilot has proposed and is waiting for approval — approving it from the
     * Copilot schedules it through the normal InterviewService path.
     *
     * @return list<array{question: string, tool: array{0: string, 1: array<string, mixed>, 2: AiRiskLevel}, pending: bool, answer: string}>
     */
    private function pendingInterviewTurn(): array
    {
        $application = CandidateApplication::query()
            ->where('status', ApplicationStatus::Active)
            ->whereIn('current_stage', [CandidateStage::Shortlisted, CandidateStage::Screened, CandidateStage::Interested])
            ->whereHas('requisition', fn ($query) => $query->where('status', RequisitionStatus::Open)->whereNotNull('assistant_manager_id'))
            ->with(['candidate', 'requisition'])
            ->orderByDesc('id')
            ->first();

        if ($application === null) {
            return [];
        }

        $interviewer = Employee::query()->findOrFail($application->requisition->assistant_manager_id);
        $slot = $this->ctx->slot($this->ctx->today->addDays(2));

        return [[
            'question' => "Schedule an HR round for {$application->candidate->full_name} with {$interviewer->fullName()} on {$slot->format('l')} at {$slot->format('g:i A')}.",
            'tool' => ['schedule_interview', [
                'application_id' => $application->id,
                'round_name' => 'HR Round',
                'interviewer_employee_id' => $interviewer->id,
                'scheduled_at' => $slot->toIso8601String(),
                'mode' => 'phone',
            ], AiRiskLevel::External],
            'pending' => true,
            'answer' => "I have prepared the interview for **{$application->candidate->full_name}** ({$application->application_code}) with {$interviewer->fullName()} on {$slot->format('D, d M \a\t g:i A')}. Please approve it below and I'll schedule it and notify everyone.",
        ]];
    }

    private function usageLogs(): void
    {
        $users = collect(['chro', 'vp_hr', 'mgr_west', 'mgr_south', 'am_west', 'r_priya', 'r_divya', 'r_rohit'])
            ->map(fn (string $key): ?int => $this->ctx->person($key)->user?->id)
            ->filter()
            ->values()
            ->all();
        $rows = [];

        for ($day = $this->ctx->today->subDays(30)->startOfDay(); $day->lessThanOrEqualTo($this->ctx->today); $day = $day->addDay()) {
            if ($day->isSunday()) {
                continue;
            }

            foreach (range(1, $this->ctx->number(4, 12)) as $ignored) {
                $at = $day->setTime(9, 30)->addMinutes($this->ctx->number(0, 540));

                if ($at->greaterThan($this->ctx->today)) {
                    continue;
                }

                $type = $this->ctx->weighted(['chat' => 55, 'tool_call' => 25, 'embedding' => 15, 'web_search' => 5]);
                $input = $type === 'embedding' ? $this->ctx->number(200, 1500) : $this->ctx->number(800, 5000);
                $output = $type === 'embedding' ? 0 : $this->ctx->number(80, 1200);
                $failed = $this->ctx->chance(0.03);

                $rows[] = [
                    'user_id' => $this->ctx->pick($users),
                    'conversation_id' => null,
                    'provider' => 'gemini',
                    'model' => $type === 'embedding' ? 'gemini-embedding-001' : $this->ctx->pick([self::MODEL, self::MODEL, 'gemini-3.5-flash-lite', 'gemini-3.1-pro-preview']),
                    'request_type' => $type,
                    'input_tokens' => $input,
                    'output_tokens' => $output,
                    'cached_tokens' => $this->ctx->number(0, (int) ($input / 3)),
                    'cost' => round(($input * 0.3 + $output * 2.5) / 1_000_000, 6),
                    'latency_ms' => $this->ctx->number(350, 4800),
                    'status' => $failed ? 'error' : 'success',
                    'created_at' => $at->toDateTimeString(),
                    'updated_at' => $at->toDateTimeString(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ai_usage_logs')->insert($chunk);
        }
    }

    private function actionLogs(): void
    {
        $applications = CandidateApplication::query()->orderByDesc('id')->limit(40)->pluck('id')->all();

        if ($applications === []) {
            return;
        }

        $actions = [
            ['r_priya', 'create_followup', AiRiskLevel::Write, 'executed', 'Created a call follow-up for tomorrow 11:00.', 9],
            ['r_priya', 'draft_candidate_email', AiRiskLevel::Recommend, 'executed', 'Drafted an interview invitation email.', 8],
            ['am_west', 'move_candidates_stage', AiRiskLevel::Write, 'executed', 'Moved 3 candidates to Shortlisted.', 7],
            ['mgr_west', 'assign_candidates_to_recruiter', AiRiskLevel::Write, 'executed', 'Assigned 5 candidates to Arjun Mehta.', 6],
            ['r_divya', 'schedule_interview', AiRiskLevel::External, 'executed', 'Scheduled the technical round with Ritu Sharma.', 5],
            ['vp_hr', 'reject_candidates', AiRiskLevel::HighImpact, 'rejected', 'Declined by the user — rejection not confirmed.', 4],
            ['r_rohit', 'send_candidate_email', AiRiskLevel::External, 'failed', 'Email provider is not configured for this workspace.', 3],
            ['am_south', 'create_followup', AiRiskLevel::Write, 'executed', 'Created follow-ups for 4 overdue offers.', 2],
            ['r_priya', 'move_candidates_stage', AiRiskLevel::Write, 'executed', 'Moved 2 candidates to Screened.', 1],
        ];

        foreach ($actions as [$person, $tool, $risk, $status, $summary, $daysAgo]) {
            $employee = $this->ctx->person($person);
            DemoContext::freeze($this->ctx->officeHours($this->ctx->today->subDays($daysAgo)->setTime($this->ctx->number(10, 17), $this->ctx->number(0, 59))));
            $ids = $this->ctx->faker->randomElements($applications, $this->ctx->number(1, 3));

            AiActionLog::query()->create([
                'user_id' => $employee->user?->id,
                'tool_name' => $tool,
                'risk_level' => $risk,
                'entity_type' => 'CandidateApplication',
                'entity_ids' => $ids,
                'input' => ['application_ids' => $ids],
                'output' => ['summary' => $summary, 'success' => $status === 'executed'],
                'result_summary' => $summary,
                'status' => $status,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function openPipelineStages(): array
    {
        return array_map(
            fn (CandidateStage $stage): string => $stage->value,
            array_filter(CandidateStage::cases(), fn (CandidateStage $stage): bool => $stage->order() < CandidateStage::Joined->order()),
        );
    }
}
