<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Incentive Statement</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .subtitle { color: #6b7280; margin-top: 2px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        th { color: #6b7280; font-weight: normal; width: 40%; }
        .section-title { font-size: 13px; font-weight: bold; margin-top: 20px; margin-bottom: 8px; }
        .amount { font-size: 16px; font-weight: bold; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <h1>{{ config('app.name') }}</h1>
    <p class="subtitle">Incentive Statement &mdash; {{ $calculation->period_start->format('F Y') }}</p>

    <table>
        <tr><th>Recruiter</th><td>{{ $calculation->employee?->fullName() }}</td></tr>
        <tr><th>Candidate</th><td>{{ $calculation->candidate?->full_name }}</td></tr>
        <tr><th>Incentive Rule</th><td>{{ $calculation->incentiveRule?->name }}</td></tr>
        <tr><th>Period</th><td>{{ $calculation->period_start->format('d M Y') }} &ndash; {{ $calculation->period_end->format('d M Y') }}</td></tr>
        <tr><th>Achievement</th><td>{{ $calculation->achievement !== null ? number_format((float) $calculation->achievement, 1).'%' : '&mdash;' }}</td></tr>
        <tr>
            <th>Applicable Slab</th>
            <td>
                @if ($calculation->incentiveSlab)
                    {{ (float) $calculation->incentiveSlab->achievement_min }}% &ndash; {{ $calculation->incentiveSlab->achievement_max !== null ? (float) $calculation->incentiveSlab->achievement_max.'%' : 'and above' }}
                @else
                    &mdash;
                @endif
            </td>
        </tr>
        <tr><th>Base Amount</th><td>&#8377;{{ number_format((float) $calculation->amount, 2) }}</td></tr>
        <tr><th>Status</th><td>{{ $calculation->status->label() }}</td></tr>
    </table>

    @if ($calculation->adjustments->isNotEmpty())
        <p class="section-title">Adjustments</p>
        <table>
            <tr>
                <th style="width: 20%">Date</th>
                <th style="width: 20%">Type</th>
                <th style="width: 20%">Amount</th>
                <th>Reason</th>
            </tr>
            @foreach ($calculation->adjustments as $adjustment)
                <tr>
                    <td>{{ $adjustment->created_at->format('d M Y') }}</td>
                    <td>{{ $adjustment->adjustment_type->label() }}</td>
                    <td>&#8377;{{ number_format((float) $adjustment->amount_delta, 2) }}</td>
                    <td>{{ $adjustment->reason }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p class="section-title">Effective Amount</p>
    <p class="amount">&#8377;{{ number_format($calculation->effectiveAmount(), 2) }}</p>

    @if ($calculation->payments->isNotEmpty())
        <p class="section-title">Payment History</p>
        <table>
            <tr>
                <th style="width: 20%">Date</th>
                <th style="width: 20%">Amount</th>
                <th>Reference</th>
            </tr>
            @foreach ($calculation->payments as $payment)
                <tr>
                    <td>{{ $payment->payment_date->format('d M Y') }}</td>
                    <td>&#8377;{{ number_format((float) $payment->amount, 2) }}</td>
                    <td>{{ $payment->payment_reference ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p class="muted">Generated {{ now()->format('d M Y, h:i A') }}</p>
</body>
</html>
