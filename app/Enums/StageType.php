<?php

namespace App\Enums;

/**
 * The kind of work a configurable hiring stage (RecruitmentStage) represents. A stage's type is
 * descriptive — it drives defaults (icon, colour, which milestone it usually maps to) and gives
 * future AI/automation a structured signal — while `milestone` (a CandidateStage) is what keeps
 * funnel/SLA/incentive analytics well-defined. New kinds of work that don't fit a named type use
 * Custom rather than growing this enum for every organisation's vocabulary.
 */
enum StageType: string
{
    case Sourcing = 'sourcing';
    case Contact = 'contact';
    case Screening = 'screening';
    case Assessment = 'assessment';
    case Interview = 'interview';
    case Selection = 'selection';
    case Offer = 'offer';
    case Joining = 'joining';
    case Onboarding = 'onboarding';
    case Custom = 'custom';
    case Terminal = 'terminal';

    public function label(): string
    {
        return match ($this) {
            self::Sourcing => 'Sourcing',
            self::Contact => 'Contact',
            self::Screening => 'Screening',
            self::Assessment => 'Assessment',
            self::Interview => 'Interview',
            self::Selection => 'Selection',
            self::Offer => 'Offer',
            self::Joining => 'Joining',
            self::Onboarding => 'Onboarding',
            self::Custom => 'Custom',
            self::Terminal => 'Terminal',
        };
    }

    public function defaultIcon(): string
    {
        return match ($this) {
            self::Sourcing => 'heroicon-o-magnifying-glass',
            self::Contact => 'heroicon-o-phone',
            self::Screening => 'heroicon-o-funnel',
            self::Assessment => 'heroicon-o-clipboard-document-check',
            self::Interview => 'heroicon-o-video-camera',
            self::Selection => 'heroicon-o-trophy',
            self::Offer => 'heroicon-o-document-text',
            self::Joining => 'heroicon-o-user-plus',
            self::Onboarding => 'heroicon-o-academic-cap',
            self::Custom => 'heroicon-o-squares-plus',
            self::Terminal => 'heroicon-o-flag',
        };
    }

    /**
     * The type a system stage seeded from the canonical CandidateStage enum is given.
     */
    public static function forMilestone(CandidateStage $stage): self
    {
        return match ($stage) {
            CandidateStage::Sourced => self::Sourcing,
            CandidateStage::ContactAttempted, CandidateStage::Connected, CandidateStage::Interested => self::Contact,
            CandidateStage::Screened, CandidateStage::Shortlisted => self::Screening,
            CandidateStage::InterviewScheduled, CandidateStage::Interview1, CandidateStage::Interview2, CandidateStage::FinalInterview => self::Interview,
            CandidateStage::Selected => self::Selection,
            CandidateStage::OfferInitiated, CandidateStage::OfferReleased, CandidateStage::OfferAccepted => self::Offer,
            CandidateStage::JoiningConfirmed, CandidateStage::Joined => self::Joining,
            CandidateStage::DocumentsCompleted, CandidateStage::OnboardingCompleted => self::Onboarding,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }
}
