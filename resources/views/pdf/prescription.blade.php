@extends('pdf.layout')

@section('heading')
    <h1>{{ __('pdf.rx_title', [], $locale) }}</h1>
    <div><strong>{{ $prescription->number }}</strong></div>
    <div class="muted">{{ __('pdf.date', [], $locale) }}: {{ $prescription->issued_on->format('d M Y') }}</div>
@endsection

@section('content')
    <table style="margin-top: 5mm;">
        <tr>
            <td style="width: 50%;" class="box">
                <div class="muted">{{ __('pdf.patient', [], $locale) }}</div>
                <strong>{{ $prescription->patient->full_name }}</strong> <span class="muted">{{ $prescription->patient->mrn }}</span>
                @if($prescription->patient->age || $prescription->patient->gender)
                    <br><span class="muted">{{ collect([$prescription->patient->age ? __('pdf.age_years', ['age' => $prescription->patient->age], $locale) : null, $prescription->patient->gender ? __('pdf.genders.'.$prescription->patient->gender->value, [], $locale) : null])->filter()->join(' · ') }}</span>
                @endif
            </td>
            <td style="width: 50%;" class="box">
                <div class="muted">{{ __('pdf.physiotherapist', [], $locale) }}</div>
                <strong>{{ $prescription->practitioner->name }}</strong>
                @if($prescription->practitioner->title)<br><span class="muted">{{ $prescription->practitioner->title }}</span>@endif
                @if($prescription->practitioner->specialization)<br><span class="muted">{{ $prescription->practitioner->specialization }}</span>@endif
            </td>
        </tr>
    </table>

    @if($prescription->diagnosis)
        <h2>{{ __('pdf.diagnosis', [], $locale) }}</h2>
        <div>{!! nl2br(e($prescription->diagnosis)) !!}</div>
    @endif

    @if(!empty($prescription->modalities))
        <h2><span lang="en" style="font-family: dejavusans;">&#8478;</span> {{ __('pdf.treatment_plan', [], $locale) }}</h2>
        <table class="grid">
            <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 34%;">{{ __('pdf.modality', [], $locale) }}</th>
                <th>{{ __('pdf.parameters', [], $locale) }}</th>
                <th class="num">{{ __('pdf.duration', [], $locale) }}</th>
                <th class="num">{{ __('pdf.sessions', [], $locale) }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($prescription->modalities as $i => $m)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><strong>{{ $m['name'] }}</strong></td>
                    <td>{{ $m['parameters'] ?? '—' }}</td>
                    <td class="num">{{ !empty($m['duration_minutes']) ? __('pdf.minutes', ['n' => $m['duration_minutes']], $locale) : '—' }}</td>
                    <td class="num">{{ $m['sessions'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($prescription->exercises))
        <h2>{{ __('pdf.home_exercises', [], $locale) }}</h2>
        <table class="grid">
            <thead><tr><th style="width: 4%;">#</th><th style="width: 40%;">{{ __('pdf.exercise', [], $locale) }}</th><th>{{ __('pdf.dosage', [], $locale) }}</th></tr></thead>
            <tbody>
            @foreach($prescription->exercises as $i => $ex)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><strong>{{ $ex['name'] }}</strong>@if(!empty($ex['notes']))<br><span class="muted">{{ $ex['notes'] }}</span>@endif</td>
                    <td>{{ $ex['dosage'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if($prescription->advice)
        <h2>{{ __('pdf.advice', [], $locale) }}</h2>
        <div class="box">{!! nl2br(e($prescription->advice)) !!}</div>
    @endif

    @if($prescription->precautions)
        <h2>{{ __('pdf.precautions', [], $locale) }}</h2>
        <div class="box">{!! nl2br(e($prescription->precautions)) !!}</div>
    @endif

    <table style="margin-top: 10mm;">
        <tr>
            <td style="width: 55%; vertical-align: bottom;">
                @if($prescription->review_on)
                    <span class="pill">{{ __('pdf.review_on', ['date' => $prescription->review_on->format('d M Y')], $locale) }}</span>
                @endif
            </td>
            <td style="width: 45%; text-align: {{ $rtl ? 'left' : 'right' }};">
                <div style="border-top: 0.3mm solid #142D36; padding-top: 2mm; display: inline-block; min-width: 55mm;">
                    <strong>{{ $prescription->practitioner->name }}</strong><br>
                    <span class="muted">{{ __('pdf.signature', [], $locale) }}</span>
                </div>
            </td>
        </tr>
    </table>
@endsection
