@php($cdata = fn (mixed $value): string => str_replace(']]>', ']]]]><![CDATA[>', (string) $value))
{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<source>
    <publisher>{{ \App\Services\Branding::tenantName() }}</publisher>
    <publisherurl>{{ route('careers.index') }}</publisherurl>
    <lastBuildDate>{{ now()->toRfc2822String() }}</lastBuildDate>
@foreach ($postings as $posting)
    <job>
        <title><![CDATA[{!! $cdata($posting->title) !!}]]></title>
        <date><![CDATA[{!! $cdata($posting->published_at?->toRfc2822String()) !!}]]></date>
        <referencenumber><![CDATA[{!! $cdata($posting->public_slug) !!}]]></referencenumber>
        <url><![CDATA[{!! $cdata(route('careers.show', ['slug' => $posting->public_slug, 'utm_source' => 'xml_feed'])) !!}]]></url>
        <company><![CDATA[{!! $cdata(\App\Services\Branding::tenantName()) !!}]]></company>
        <city><![CDATA[{!! $cdata($posting->requisition->location?->name) !!}]]></city>
        <country><![CDATA[IN]]></country>
        <description><![CDATA[{!! $cdata($posting->description) !!}]]></description>
        <jobtype><![CDATA[{!! $cdata($posting->requisition->employment_type?->label()) !!}]]></jobtype>
        <category><![CDATA[{!! $cdata($posting->requisition->department?->name) !!}]]></category>
@if ($posting->show_salary && $posting->requisition->salary_max)
        <salary><![CDATA[{!! $cdata(number_format((float) $posting->requisition->salary_min).' - '.number_format((float) $posting->requisition->salary_max).' per year') !!}]]></salary>
@endif
    </job>
@endforeach
</source>
