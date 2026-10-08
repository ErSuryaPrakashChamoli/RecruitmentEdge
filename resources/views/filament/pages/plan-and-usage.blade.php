@php($summary = $this->summary())
<x-filament-panels::page>
    <x-filament::section heading="Your plan">
        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Plan</dt>
                <dd class="font-medium">{{ $summary['plan'] ?? 'No plan' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Account</dt>
                <dd class="font-medium">{{ $summary['status'] }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ $summary['trial_ends_at'] !== null ? 'Trial ends' : 'Plan since' }}</dt>
                <dd class="font-medium">{{ $summary['trial_ends_at'] ?? $summary['since'] ?? '—' }}</dd>
            </div>
        </dl>
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">To change your plan, contact your Recruitment Edge account manager.</p>
    </x-filament::section>

    <x-filament::section heading="What your plan includes">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10">
                    <th class="py-2 font-medium">Capability</th>
                    <th class="py-2 font-medium">Included</th>
                    <th class="py-2 font-medium">In use</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary['lines'] as $line)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2">{{ $line['label'] }}</td>
                        <td class="py-2">{{ $line['granted'] }}</td>
                        <td class="py-2">
                            @if ($line['kind'] === 'limit')
                                <span @class(['font-medium text-danger-600 dark:text-danger-400' => $line['near']])>{{ $line['usage'] }}</span>
                                @if ($line['over'])
                                    <span class="text-xs text-danger-600 dark:text-danger-400">— over the plan's limit: existing records stay, new ones wait until usage is below it.</span>
                                @elseif ($line['near'])
                                    <span class="text-xs text-danger-600 dark:text-danger-400">— limit reached</span>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
