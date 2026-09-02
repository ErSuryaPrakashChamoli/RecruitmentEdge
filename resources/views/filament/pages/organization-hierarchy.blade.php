<x-filament-panels::page>
    <x-filament::section heading="Organization Hierarchy" description="CHRO → VP HR → Manager → Assistant Manager → Recruiter">
        @php $trees = $this->getTrees(); @endphp

        @if (empty($trees))
            <x-recruitment.empty-state
                icon="heroicon-o-share"
                heading="No hierarchy to show"
                description="You don't have an employee record with a reporting hierarchy under it yet."
            />
        @else
            <div class="space-y-4">
                @foreach ($trees as $tree)
                    @include('filament.pages.organization-hierarchy-node', ['node' => $tree, 'depth' => 0])
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
