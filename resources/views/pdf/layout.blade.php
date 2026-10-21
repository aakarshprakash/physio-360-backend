<!doctype html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
@php($accent = $tenant->setting('invoice.accent_color') ?: '#2F7D66')
<style>
    body { font-family: dejavusans, sans-serif; font-size: 9.5pt; color: #1d2b30; }
    h1 { font-size: 17pt; color: #142D36; margin: 0 0 2mm 0; }
    h2 { font-size: 10.5pt; color: #142D36; margin: 6mm 0 2mm 0; text-transform: uppercase; letter-spacing: 0.3pt; }
    .muted { color: #5b6b70; }
    .brand { color: #142D36; font-weight: bold; font-size: 15pt; }
    .header td { vertical-align: top; }
    table { width: 100%; border-collapse: collapse; }
    .grid th { background: #142D36; color: #fff; font-weight: bold; padding: 2.2mm; text-align: {{ $rtl ? 'right' : 'left' }}; font-size: 8.5pt; }
    .grid td { padding: 2.2mm; border-bottom: 0.2mm solid #dce6e2; vertical-align: top; }
    .num { text-align: {{ $rtl ? 'left' : 'right' }}; white-space: nowrap; }
    .totals td { padding: 1.4mm 2.2mm; }
    .totals .grand td { font-weight: bold; font-size: 11pt; border-top: 0.4mm solid #142D36; }
    .pill { background: #DCEDE6; color: #1f5f4d; padding: 1mm 2.5mm; font-size: 8pt; font-weight: bold; }
    .box { background: #F7FAF8; border: 0.2mm solid #dce6e2; padding: 3mm; }
    .footer-note { margin-top: 8mm; font-size: 8pt; color: #5b6b70; }
    .band { height: 2.2mm; background: {{ $accent }}; }
</style>
</head>
<body>
<div class="band"></div>
<table class="header" style="margin-top: 4mm; border-bottom: 0.3mm solid #dce6e2; padding-bottom: 3mm;">
    <tr>
        @if($logo)
            <td style="width: 22mm; padding-{{ $rtl ? 'left' : 'right' }}: 3mm;"><img src="{{ $logo }}" style="max-height: 18mm; max-width: 20mm;"></td>
        @endif
        <td>
            <div class="brand">{{ $tenant->name }}</div>
            @if($branch)<div class="muted">{{ $branch->name }}@if($branch->city), {{ $branch->city }}@endif</div>@endif
            @if($branch?->address || $tenant->address)<div class="muted">{{ $branch?->address ?: $tenant->address }}</div>@endif
            <div class="muted">{{ collect([$branch?->phone ?? $tenant->phone, $branch?->email ?? $tenant->email])->filter()->join(' · ') }}</div>
            @if($tenant->tax_number)<div class="muted">{{ __('pdf.tax_number', [], $locale) }}: {{ $tenant->tax_number }}</div>@endif
        </td>
        <td style="width: 40%; text-align: {{ $rtl ? 'left' : 'right' }};">
            @yield('heading')
        </td>
    </tr>
</table>
@yield('content')
<div class="footer-note">{{ __('pdf.generated', ['date' => $generatedAt], $locale) }} · Physio360 by SYSNAC</div>
</body>
</html>
