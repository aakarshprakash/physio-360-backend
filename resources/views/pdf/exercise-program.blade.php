@extends('pdf.layout')

@section('heading')
    <h1>{{ __('pdf.hep_title', [], $locale) }}</h1>
    <div class="muted">{{ __('pdf.hep_subtitle', [], $locale) }}</div>
    <div style="margin-top: 2mm;"><span class="pill">{{ __('pdf.week_of', ['current' => max(1, $plan->current_week), 'total' => $plan->duration_weeks], $locale) }}</span></div>
@endsection

@section('content')
    <table style="margin-top: 5mm;">
        <tr>
            <td style="width: 50%;" class="box">
                <div class="muted">{{ __('pdf.patient', [], $locale) }}</div>
                <strong>{{ $plan->patient->full_name }}</strong> <span class="muted">{{ $plan->patient->mrn }}</span>
            </td>
            <td style="width: 50%;" class="box">
                <div class="muted">{{ __('pdf.physiotherapist', [], $locale) }}</div>
                <strong>{{ $plan->practitioner->name }}</strong>
                @if($plan->practitioner->title)<span class="muted"> · {{ $plan->practitioner->title }}</span>@endif
            </td>
        </tr>
    </table>

    <h2>{{ $plan->title }}</h2>
    <div class="muted">{{ __('pdf.start_date', [], $locale) }}: {{ $plan->start_date->format('d M Y') }} · {{ __('pdf.duration_weeks', ['weeks' => $plan->duration_weeks], $locale) }}</div>
    @if($plan->goals)<p><strong>{{ __('pdf.goals', [], $locale) }}:</strong> {{ $plan->goals }}</p>@endif

    <table class="grid" style="margin-top: 3mm;">
        <thead>
        <tr>
            <th style="width: 4%;">#</th>
            <th style="width: 30%;">{{ __('pdf.exercise', [], $locale) }}</th>
            <th>{{ __('pdf.dosage', [], $locale) }}</th>
            <th>{{ __('pdf.frequency', [], $locale) }}</th>
            <th style="width: 38%;">{{ __('pdf.instructions', [], $locale) }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($plan->exercises as $i => $row)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td><strong>{{ $row->exercise->name }}</strong><br><span class="muted">{{ ucfirst($row->exercise->body_region) }}</span></td>
                <td>
                    {{ $row->sets }} {{ __('pdf.sets', [], $locale) }}
                    @if($row->reps) × {{ $row->reps }} {{ __('pdf.reps', [], $locale) }}@endif
                    @if($row->hold_seconds)<br>{{ __('pdf.hold', ['seconds' => $row->hold_seconds], $locale) }}@endif
                </td>
                <td>{{ $row->frequency }}</td>
                <td>{{ $row->exercise->instructions }}@if($row->notes)<br><em>{{ $row->notes }}</em>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="box" style="margin-top: 6mm;">{{ __('pdf.hep_safety', [], $locale) }}</div>
@endsection
