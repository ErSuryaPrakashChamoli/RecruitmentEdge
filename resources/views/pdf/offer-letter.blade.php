@php
    $application = $offer->candidateApplication;
    $requisition = $application?->requisition;
    $designation = $offer->designation ?? $requisition?->designation;
    $location = $offer->location ?? $requisition?->location;
    $money = fn ($amount) => $amount !== null ? '&#8377;'.number_format((float) $amount, 2) : '&mdash;';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Offer Letter</title>
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
    <p class="subtitle">Offer Letter &mdash; {{ $offer->offer_code }}</p>

    <p>Dear {{ $application?->candidate?->full_name }},</p>
    <p>
        We are pleased to offer you the position of <strong>{{ $designation?->name ?? '—' }}</strong>
        on the terms below.
    </p>

    <table>
        <tr><th>Offer Code</th><td>{{ $offer->offer_code }}</td></tr>
        <tr><th>Candidate</th><td>{{ $application?->candidate?->full_name }}</td></tr>
        <tr><th>Designation</th><td>{{ $designation?->name ?? '—' }}</td></tr>
        <tr><th>Department</th><td>{{ $requisition?->department?->name ?? '—' }}</td></tr>
        <tr><th>Location</th><td>{{ $location?->name ?? '—' }}</td></tr>
        <tr><th>Offer Date</th><td>{{ $offer->offer_date?->format('d M Y') ?? '—' }}</td></tr>
        <tr><th>Expected Date of Joining</th><td>{{ $offer->expected_joining_date?->format('d M Y') ?? '—' }}</td></tr>
        <tr><th>Offer Valid Until</th><td>{{ $offer->offer_expiry?->format('d M Y') ?? '—' }}</td></tr>
    </table>

    <p class="section-title">Compensation</p>
    <table>
        <tr><th>Fixed Salary</th><td>{!! $money($offer->fixed_salary) !!}</td></tr>
        <tr><th>Variable Pay</th><td>{!! $money($offer->variable_salary) !!}</td></tr>
        <tr><th>Joining Bonus</th><td>{!! $money($offer->joining_bonus) !!}</td></tr>
    </table>

    <p class="section-title">Offered CTC</p>
    <p class="amount">{!! $money($offer->offered_ctc) !!}</p>

    @if ($offer->offer_expiry)
        <p>Please confirm your acceptance on or before {{ $offer->offer_expiry->format('d M Y') }}, after which this offer lapses.</p>
    @endif

    <p class="muted">Generated {{ now()->format('d M Y, h:i A') }}</p>
</body>
</html>
