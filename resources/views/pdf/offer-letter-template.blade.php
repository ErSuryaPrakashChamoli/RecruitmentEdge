<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Offer Letter &mdash; {{ $offer->offer_code }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f2937; line-height: 1.5; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        h2 { font-size: 16px; margin: 16px 0 6px; }
        h3 { font-size: 13px; margin: 14px 0 6px; }
        p { margin: 0 0 8px; }
        ul, ol { margin: 0 0 8px; padding-left: 18px; }
        blockquote { border-left: 3px solid #e5e7eb; margin: 0 0 8px; padding-left: 10px; color: #4b5563; }
        hr { border: 0; border-top: 1px solid #e5e7eb; margin: 12px 0; }
        table { width: 100%; border-collapse: collapse; margin: 0 0 10px; }
        th, td { text-align: left; padding: 6px 8px; border: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    {{-- Sanitized by RichContentRenderer::toHtml() in OfferLetterRenderer. --}}
    {!! $bodyHtml !!}
</body>
</html>
