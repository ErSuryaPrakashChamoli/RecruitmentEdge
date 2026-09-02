<?php

namespace App\Services\AI\Tools;

use App\Services\AI\Tools\ActionTools\AssignCandidatesToRecruiterTool;
use App\Services\AI\Tools\ActionTools\CreateFollowupTool;
use App\Services\AI\Tools\ActionTools\DraftCandidateEmailTool;
use App\Services\AI\Tools\ActionTools\MoveCandidatesStageTool;
use App\Services\AI\Tools\ActionTools\RejectCandidatesTool;
use App\Services\AI\Tools\ActionTools\ScheduleInterviewTool;
use App\Services\AI\Tools\ActionTools\SendCandidateEmailTool;
use App\Services\AI\Tools\CandidateTools\CompareCandidatesTool;
use App\Services\AI\Tools\CandidateTools\FindDuplicateCandidatesTool;
use App\Services\AI\Tools\CandidateTools\FindStuckCandidatesTool;
use App\Services\AI\Tools\CandidateTools\GetCandidateTimelineTool;
use App\Services\AI\Tools\CandidateTools\GetCandidateTool;
use App\Services\AI\Tools\CandidateTools\SearchCandidatesTool;
use App\Services\AI\Tools\CandidateTools\SummarizeCandidateTool;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\AI\Tools\InterviewTools\GenerateInterviewPlanTool;
use App\Services\AI\Tools\InterviewTools\GenerateInterviewQuestionsTool;
use App\Services\AI\Tools\InterviewTools\SummarizeInterviewFeedbackTool;
use App\Services\AI\Tools\JobTools\FindAtRiskRequisitionsTool;
use App\Services\AI\Tools\JobTools\GenerateJdTool;
use App\Services\AI\Tools\JobTools\GetRequisitionTool;
use App\Services\AI\Tools\JobTools\ImproveJdTool;
use App\Services\AI\Tools\JobTools\SearchRequisitionsTool;
use App\Services\AI\Tools\KnowledgeTools\SearchKnowledgeBaseTool;
use App\Services\AI\Tools\OfferTools\AnalyzeJoiningConversionTool;
use App\Services\AI\Tools\OfferTools\AnalyzeOffersTool;
use App\Services\AI\Tools\OfferTools\FindJoiningRisksTool;
use App\Services\AI\Tools\PlannerTools\BuildRecruitmentPlanTool;
use App\Services\AI\Tools\RecruiterTools\CompareRecruitersTool;
use App\Services\AI\Tools\RecruiterTools\FindInactiveRecruitersTool;
use App\Services\AI\Tools\RecruiterTools\GetRecruiterPerformanceTool;
use App\Services\AI\Tools\RecruitmentTools\AnalyzeFunnelTool;
use App\Services\AI\Tools\RecruitmentTools\AnalyzeSourcesTool;
use App\Services\AI\Tools\RecruitmentTools\ForecastHiringTool;
use App\Services\AI\Tools\RecruitmentTools\GenerateDashboardInsightsTool;
use App\Services\AI\Tools\RecruitmentTools\TimeToHireTool;
use App\Services\AI\Tools\ResearchTools\WebResearchTool;

/**
 * Every AiTool implementation the Copilot knows about. Add a new tool by creating the class and
 * adding it here — ToolRegistry handles permission filtering, and AiOrchestrator/ActionExecutor
 * handle risk-based execution automatically from there.
 */
class ToolRegistrar
{
    /**
     * @var array<int, class-string<AiTool>>
     */
    public const array CLASSES = [
        // Candidate
        SearchCandidatesTool::class,
        GetCandidateTool::class,
        CompareCandidatesTool::class,
        SummarizeCandidateTool::class,
        FindStuckCandidatesTool::class,
        FindDuplicateCandidatesTool::class,
        GetCandidateTimelineTool::class,
        // Job / requisition
        SearchRequisitionsTool::class,
        GetRequisitionTool::class,
        GenerateJdTool::class,
        ImproveJdTool::class,
        FindAtRiskRequisitionsTool::class,
        // Recruitment analytics
        AnalyzeFunnelTool::class,
        AnalyzeSourcesTool::class,
        TimeToHireTool::class,
        ForecastHiringTool::class,
        GenerateDashboardInsightsTool::class,
        // Recruiter
        GetRecruiterPerformanceTool::class,
        CompareRecruitersTool::class,
        FindInactiveRecruitersTool::class,
        // Interview
        GenerateInterviewQuestionsTool::class,
        GenerateInterviewPlanTool::class,
        SummarizeInterviewFeedbackTool::class,
        // Offer / joining
        AnalyzeOffersTool::class,
        AnalyzeJoiningConversionTool::class,
        FindJoiningRisksTool::class,
        // Knowledge / research / planning
        SearchKnowledgeBaseTool::class,
        WebResearchTool::class,
        BuildRecruitmentPlanTool::class,
        // Actions
        AssignCandidatesToRecruiterTool::class,
        MoveCandidatesStageTool::class,
        RejectCandidatesTool::class,
        CreateFollowupTool::class,
        ScheduleInterviewTool::class,
        DraftCandidateEmailTool::class,
        SendCandidateEmailTool::class,
    ];

    public static function registerAll(ToolRegistry $registry): void
    {
        foreach (self::CLASSES as $class) {
            $registry->register(app($class));
        }
    }
}
