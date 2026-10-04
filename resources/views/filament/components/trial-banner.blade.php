@php($tenant = \App\Services\Tenancy\TenantContext::current()->tenant())
@if ($tenant?->status === \App\Enums\TenantStatus::Trial && $tenant->trial_ends_at !== null && $tenant->trial_ends_at->isFuture())
    <div role="status" class="border-b border-warning-200 bg-warning-50 px-4 py-2 text-center text-sm text-warning-800 dark:border-warning-400/20 dark:bg-warning-400/10 dark:text-warning-300">
        Trial — ends on {{ $tenant->trial_ends_at->toFormattedDateString() }}. To continue afterwards, contact your Recruitment Edge account manager.
    </div>
@endif
