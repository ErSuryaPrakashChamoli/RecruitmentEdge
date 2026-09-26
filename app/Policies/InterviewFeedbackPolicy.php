<?php

namespace App\Policies;

use App\Models\InterviewFeedback;
use App\Models\User;
use App\Services\InterviewFeedbackService;

/**
 * Phase 8.3: feedback follows its interview's visibility. It is never edited or deleted in place —
 * submission and correction go through InterviewFeedbackService.
 */
class InterviewFeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('interviews.manage');
    }

    public function view(User $user, InterviewFeedback $feedback): bool
    {
        return $user->can('view', $feedback->interview);
    }

    public function create(User $user): bool
    {
        return $user->can('interviews.manage');
    }

    public function correct(User $user, InterviewFeedback $feedback): bool
    {
        return app(InterviewFeedbackService::class)->canCorrect($user, $feedback);
    }

    public function update(User $user, InterviewFeedback $feedback): bool
    {
        return false;
    }

    public function delete(User $user, InterviewFeedback $feedback): bool
    {
        return false;
    }
}
