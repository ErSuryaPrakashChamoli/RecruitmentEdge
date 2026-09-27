@props(['result', 'label' => null, 'color' => 'default'])
@php
    /**
     * Phase 8.5: a governed metric (App\Services\Metrics\MetricResult) as a KPI card. The value is
     * never a stand-in zero — a withheld value shows why (No data / Too few / Unknown) — and the
     * hover text carries the registered definition and what the number rests on.
     *
     * @var \App\Services\Metrics\MetricResult $result
     */
    $spec = app(\App\Services\Metrics\MetricService::class)->spec($result->key);
    $definition = $spec->description.' — '.$result->basisLine();
@endphp

<x-recruitment.kpi-card
    :label="$label ?? $spec->name"
    :value="$result->display()"
    :color="$color"
    :title="$definition"
    data-metric="{{ $result->key }}"
    data-metric-version="{{ $result->version }}"
    {{ $attributes }}
/>
