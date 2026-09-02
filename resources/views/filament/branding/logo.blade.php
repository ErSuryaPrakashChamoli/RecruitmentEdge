<div class="flex flex-col leading-none">
    <span class="text-lg font-bold tracking-tight text-gray-950 dark:text-white">{{ config('app.name') }}</span>
    @if ($company = config('app.company_name'))
        <span class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">by {{ $company }}</span>
    @endif
</div>
