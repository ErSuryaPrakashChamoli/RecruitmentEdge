<?php

namespace App\Policies;

use App\Models\JobPosting;
use App\Models\User;

/**
 * Job postings are managed with jobs.publish, scoped to requisitions the user can see
 * (RecruitmentRequisitionPolicy — the multi-column hierarchy scope).
 */
class JobPostingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('jobs.publish');
    }

    public function view(User $user, JobPosting $jobPosting): bool
    {
        return $user->can('jobs.publish') && $user->can('view', $jobPosting->requisition);
    }

    public function create(User $user): bool
    {
        return $user->can('jobs.publish');
    }

    public function update(User $user, JobPosting $jobPosting): bool
    {
        return $this->view($user, $jobPosting);
    }

    public function delete(User $user, JobPosting $jobPosting): bool
    {
        return false;
    }
}
