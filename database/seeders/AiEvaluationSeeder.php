<?php

namespace Database\Seeders;

use App\Models\AiEvaluation;
use Illuminate\Database\Seeder;

/**
 * The test prompts from the master AI Copilot spec (section 52), mapped to the tool that should
 * handle each one. Run with `php artisan ai:evaluate` — see AiEvaluateCommand for what's checked.
 */
class AiEvaluationSeeder extends Seeder
{
    public function run(): void
    {
        $cases = [
            [
                'name' => 'Candidates assigned to me',
                'category' => 'internal',
                'question' => 'Show me all candidates assigned to me.',
                'expected_intent' => 'read_internal_data',
                'expected_tool' => 'search_candidates',
                'expected_permission' => 'candidates.viewAny',
            ],
            [
                'name' => 'Stuck candidates',
                'category' => 'internal',
                'question' => 'Which candidates are stuck for more than 7 days?',
                'expected_intent' => 'read_internal_data',
                'expected_tool' => 'find_stuck_candidates',
                'expected_permission' => 'candidates.viewAny',
            ],
            [
                'name' => 'Hiring performance drop explanation',
                'category' => 'internal',
                'question' => 'Why has our hiring performance dropped this month?',
                'expected_intent' => 'analyze_internal_data',
                'expected_tool' => 'analyze_funnel',
                'expected_permission' => 'performance.view',
            ],
            [
                'name' => 'Generate a JD',
                'category' => 'generation',
                'question' => 'Create a JD for Senior Laravel Developer.',
                'expected_intent' => 'generate_content',
                'expected_tool' => 'generate_jd',
                'expected_permission' => 'requisitions.create',
            ],
            [
                'name' => 'Current salary range research',
                'category' => 'external',
                'question' => 'What is the current salary range for Senior Laravel Developers in Delhi NCR?',
                'expected_intent' => 'external_research',
                'expected_tool' => 'web_research',
                'expected_permission' => 'ai.query',
            ],
            [
                'name' => 'Compare internal performance with market trends',
                'category' => 'combined',
                'question' => 'Compare our hiring performance with current market trends.',
                'expected_intent' => 'combined_internal_external',
                'expected_tool' => 'web_research',
                'expected_permission' => 'ai.query',
            ],
            [
                'name' => 'Hiring plan for a volume target',
                'category' => 'planning',
                'question' => 'Create a hiring plan for 50 sales executives in 45 days.',
                'expected_intent' => 'build_plan',
                'expected_tool' => 'build_recruitment_plan',
                'expected_permission' => 'requisitions.create',
            ],
            [
                'name' => 'Underperforming recruiters',
                'category' => 'internal',
                'question' => 'Which recruiters are underperforming?',
                'expected_intent' => 'analyze_internal_data',
                'expected_tool' => 'compare_recruiters',
                'expected_permission' => 'performance.view',
            ],
            [
                'name' => 'Generate interview questions',
                'category' => 'generation',
                'question' => 'Generate interview questions for this candidate.',
                'expected_intent' => 'generate_content',
                'expected_tool' => 'generate_interview_questions',
                'expected_permission' => 'interviews.manage',
            ],
            [
                'name' => 'Bulk assign candidates (permission + confirmation boundary)',
                'category' => 'action',
                'question' => 'Assign these 10 candidates to Rahul.',
                'expected_intent' => 'write_action',
                'expected_tool' => 'assign_candidates_to_recruiter',
                'expected_permission' => 'candidates.reassign',
                'assertions' => ['requires_confirmation' => true],
            ],
            [
                'name' => 'Duplicate candidate check',
                'category' => 'internal',
                'question' => 'Are there any likely duplicates for this candidate?',
                'expected_intent' => 'read_internal_data',
                'expected_tool' => 'find_duplicate_candidates',
                'expected_permission' => 'candidates.viewAny',
            ],
            [
                'name' => 'At-risk requisitions',
                'category' => 'internal',
                'question' => 'Which positions are at risk?',
                'expected_intent' => 'read_internal_data',
                'expected_tool' => 'find_at_risk_requisitions',
                'expected_permission' => 'requisitions.viewAny',
            ],
            [
                'name' => 'Dashboard insights prioritization',
                'category' => 'internal',
                'question' => 'What should I work on today?',
                'expected_intent' => 'analyze_internal_data',
                'expected_tool' => 'generate_dashboard_insights',
                'expected_permission' => 'ai.query',
            ],
            [
                'name' => 'Bulk rejection (high-impact confirmation boundary)',
                'category' => 'high_impact_action',
                'question' => 'Reject these candidates as not interested.',
                'expected_intent' => 'high_impact_action',
                'expected_tool' => 'reject_candidates',
                'expected_permission' => 'pipeline.transition',
                'assertions' => ['requires_confirmation' => true],
            ],
        ];

        foreach ($cases as $case) {
            AiEvaluation::query()->updateOrCreate(['name' => $case['name']], $case);
        }
    }
}
