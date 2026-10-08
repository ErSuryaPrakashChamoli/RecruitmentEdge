@php($summary = $this->summary())
@php($customer = $this->customer())
<x-filament-panels::page>
    <x-filament::section heading="Subscription">
        @if ($summary['subscription_id'] === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">There is no subscription for this organisation. To subscribe, contact your Recruitment Edge account manager.</p>
        @else
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Plan</dt>
                    <dd class="font-medium">{{ $summary['plan'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="font-medium">{{ $summary['status_label'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Price</dt>
                    <dd class="font-medium">{{ $summary['amount'] }} · {{ $summary['interval'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Current period</dt>
                    <dd class="font-medium">{{ $summary['period_start'] ?? '—' }} – {{ $summary['period_end'] ?? '—' }}</dd>
                </div>
            </dl>
            @if ($summary['grace_ends_at'] !== null)
                <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">A payment failed. Access continues until {{ $summary['grace_ends_at'] }}; once the invoice is paid, nothing changes.</p>
            @elseif ($summary['cancel_at'] !== null)
                <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">The subscription ends on {{ $summary['cancel_at'] }}. Your data is kept.</p>
            @endif
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">To change your plan, contact your Recruitment Edge account manager.</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Billing details">
        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Legal name</dt>
                <dd class="font-medium">{{ $customer?->legal_name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Billing email</dt>
                <dd class="font-medium">{{ $customer?->email ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Billing contact</dt>
                <dd class="font-medium">{{ $customer?->contact?->name ?? '—' }}</dd>
            </div>
        </dl>
    </x-filament::section>

    <x-filament::section heading="Invoices">
        @php($invoices = $this->invoices())
        @if ($invoices->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No invoices yet.</p>
        @else
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="py-2 font-medium">Invoice</th>
                        <th class="py-2 font-medium">Period</th>
                        <th class="py-2 font-medium">Total</th>
                        <th class="py-2 font-medium">Due</th>
                        <th class="py-2 font-medium">Status</th>
                        <th class="py-2 font-medium">Last payment</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoices as $invoice)
                        @php($lastPayment = $invoice->payments->sortByDesc('id')->first())
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 font-medium">{{ $invoice->number }}</td>
                            <td class="py-2">{{ $invoice->period_start->toFormattedDateString() }} – {{ $invoice->period_end->toFormattedDateString() }}</td>
                            <td class="py-2">{{ $invoice->total()->format() }}</td>
                            <td class="py-2">{{ $invoice->amountDue()->format() }}</td>
                            <td class="py-2">{{ $invoice->status->label() }}</td>
                            <td class="py-2">{{ $lastPayment?->status->label() ?? '—' }}{{ $lastPayment?->failure_message !== null ? ' — '.$lastPayment->failure_message : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
