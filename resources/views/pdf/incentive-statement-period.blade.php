<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Incentive Statement</title>
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .subtitle { color: #6b7280; margin-top: 2px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { text-align: left; padding: 5px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { color: #6b7280; font-weight: normal; }
        .num { text-align: right; white-space: nowrap; }
        .section-title { font-size: 12px; font-weight: bold; margin-top: 16px; margin-bottom: 6px; }
        .muted { color: #6b7280; }
        .total td { font-weight: bold; border-top: 1px solid #9ca3af; }
    </style>
</head>
<body>
    <h1>{{ config('app.name') }}</h1>
    <p class="subtitle">Incentive Statement &mdash; {{ $periodStart->format('F Y') }}</p>

    <table>
        <tr><th style="width: 25%">Recruiter</th><td>{{ $recruiter->fullName() }}</td></tr>
        <tr><th>Employee Code</th><td>{{ $recruiter->employee_code ?? '—' }}</td></tr>
        <tr><th>Period</th><td>{{ $periodStart->format('d M Y') }} &ndash; {{ $periodEnd->format('d M Y') }}</td></tr>
        <tr><th>Total Earned (excl. rejected/reversed)</th><td>&#8377;{{ number_format($earnedTotal, 2) }}</td></tr>
        <tr><th>Total Paid</th><td>&#8377;{{ number_format($paidTotal, 2) }}</td></tr>
    </table>

    <p class="section-title">Calculations</p>
    @if ($calculations->isEmpty())
        <p class="muted">No incentive calculations for this period.</p>
    @else
        <table>
            <tr>
                <th>Rule</th>
                <th>Application / Candidate</th>
                <th class="num">Achievement</th>
                <th>Slab</th>
                <th class="num">Amount</th>
                <th class="num">Adjustments</th>
                <th class="num">Effective</th>
                <th>Status</th>
            </tr>
            @foreach ($calculations as $calculation)
                <tr>
                    <td>{{ $calculation->incentiveRule?->name ?? '—' }}</td>
                    <td>
                        {{ $calculation->candidateApplication?->application_code ?? '—' }}<br>
                        <span class="muted">{{ $calculation->candidate?->full_name }}</span>
                    </td>
                    <td class="num">{{ $calculation->slabBasis() !== null ? $calculation->incentiveRule->formatSlabBasis($calculation->slabBasis()) : '—' }}</td>
                    <td>
                        @if ($calculation->incentiveSlab)
                            {{ $calculation->incentiveSlab->bandLabel($calculation->incentiveRule) }}
                        @else
                            {{ $calculation->incentiveRule?->payout_type?->label() ?? '—' }}
                        @endif
                    </td>
                    <td class="num">&#8377;{{ number_format((float) $calculation->amount, 2) }}</td>
                    <td class="num">
                        @forelse ($calculation->adjustments as $adjustment)
                            {{ $adjustment->adjustment_type->label() }}: &#8377;{{ number_format((float) $adjustment->amount_delta, 2) }}<br>
                        @empty
                            &mdash;
                        @endforelse
                    </td>
                    <td class="num">&#8377;{{ number_format($effectiveAmounts[$calculation->id], 2) }}</td>
                    <td>{{ $calculation->status->label() }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p class="section-title">Totals by Status</p>
    <table>
        <tr>
            <th>Status</th>
            <th class="num">Calculations</th>
            <th class="num">Effective Amount</th>
        </tr>
        @foreach ($totalsByStatus as $total)
            <tr>
                <td>{{ $total['label'] }}</td>
                <td class="num">{{ $total['count'] }}</td>
                <td class="num">&#8377;{{ number_format($total['amount'], 2) }}</td>
            </tr>
        @endforeach
    </table>

    <p class="section-title">Payments</p>
    @if ($payments->isEmpty())
        <p class="muted">No payments recorded against this period's calculations.</p>
    @else
        <table>
            <tr>
                <th>Date</th>
                <th>Rule / Candidate</th>
                <th>Reference</th>
                <th class="num">Amount</th>
            </tr>
            @foreach ($payments as $payment)
                <tr>
                    <td>{{ $payment->payment_date->format('d M Y') }}</td>
                    <td>{{ $payment->calculation?->incentiveRule?->name }} &middot; {{ $payment->calculation?->candidate?->full_name }}</td>
                    <td>{{ $payment->payment_reference ?? '—' }}</td>
                    <td class="num">&#8377;{{ number_format((float) $payment->amount, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">Total Paid</td>
                <td class="num">&#8377;{{ number_format($paidTotal, 2) }}</td>
            </tr>
        </table>
    @endif

    <p class="muted">Generated {{ now()->format('d M Y, h:i A') }}</p>
</body>
</html>
