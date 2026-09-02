<?php

namespace App\Enums;

/**
 * The configurable target metrics from Section 19. See RecruiterDailyMetricsService::actualFor()
 * for how each metric's actual value is computed from authoritative fact tables.
 */
enum TargetMetric: string
{
    case ProfilesSourced = 'profiles_sourced';
    case Calls = 'calls';
    case ConnectedCalls = 'connected_calls';
    case InterestedCandidates = 'interested_candidates';
    case Screening = 'screening';
    case Shortlisted = 'shortlisted';
    case Interviews = 'interviews';
    case Selections = 'selections';
    case Offers = 'offers';
    case Joining = 'joining';

    public function label(): string
    {
        return match ($this) {
            self::ProfilesSourced => 'Profiles Sourced',
            self::Calls => 'Calls',
            self::ConnectedCalls => 'Connected Calls',
            self::InterestedCandidates => 'Interested Candidates',
            self::Screening => 'Screening',
            self::Shortlisted => 'Shortlisted',
            self::Interviews => 'Interviews',
            self::Selections => 'Selections',
            self::Offers => 'Offers',
            self::Joining => 'Joining',
        };
    }
}
