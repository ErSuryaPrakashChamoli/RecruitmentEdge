@php($tenant = \App\Services\Tenancy\TenantContext::current()->tenant())
@if ($tenant?->status === \App\Enums\TenantStatus::Trial && $tenant->trial_ends_at !== null && $tenant->trial_ends_at->isFuture())
    <div role="status" class="border-b border-warning-200 bg-warning-50 px-4 py-2 text-center text-sm text-warning-800 dark:border-warning-400/20 dark:bg-warning-400/10 dark:text-warning-300">
        Trial — ends on {{ $tenant->trial_ends_at->toFormattedDateString() }}. To continue afterwards, contact your Recruitment Edge account manager.
    </div>
@elseif ($tenant?->status === \App\Enums\TenantStatus::PastDue && $tenant->access_ends_at !== null && $tenant->access_ends_at->isFuture())
    {{-- SaaS-4: a failed payment's grace period. --}}
    <div role="status" class="border-b border-danger-200 bg-danger-50 px-4 py-2 text-center text-sm text-danger-800 dark:border-danger-400/20 dark:bg-danger-400/10 dark:text-danger-300">
        Payment overdue — access continues until {{ $tenant->access_ends_at->toFormattedDateString() }}. Your billing administrator can see the invoice under Billing.
    </div>
@elseif ($tenant?->status === \App\Enums\TenantStatus::Active && $tenant->access_ends_at !== null && $tenant->access_ends_at->isFuture())
    {{-- SaaS-4: a subscription cancelled at the end of its paid period. --}}
    <div role="status" class="border-b border-warning-200 bg-warning-50 px-4 py-2 text-center text-sm text-warning-800 dark:border-warning-400/20 dark:bg-warning-400/10 dark:text-warning-300">
        The subscription ends on {{ $tenant->access_ends_at->toFormattedDateString() }}. Your data is kept.
    </div>
@endif
