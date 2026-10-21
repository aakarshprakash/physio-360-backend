@php
    $accent = $tenant->setting('invoice.accent_color') ?: '#2F7D66';
    $ink = '#142D36';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $status = $invoice->status->value === 'void' ? 'void' : ($invoice->payment_status ?? $invoice->status->value);
    $statusColors = [
        'paid' => ['#DCEDE6', '#1f5f4d'], 'partially_paid' => ['#FDF0D5', '#8a5a00'], 'unpaid' => ['#FBE3E1', '#9b2c22'],
        'void' => ['#ECEFF1', '#455a64'], 'draft' => ['#ECEFF1', '#455a64'], 'credited' => ['#E3ECFA', '#24477d'],
    ];
    [$statusBg, $statusFg] = $statusColors[$status] ?? $statusColors['draft'];
    $isTax = $invoice->tax_minor > 0;
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<style>
    body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #22313a; line-height: 1.35; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .muted { color: #66767c; }
    .small { font-size: 7.8pt; }
    .label { font-size: 7.2pt; color: #66767c; text-transform: uppercase; letter-spacing: 0.4pt; font-weight: bold; }
    .num { text-align: {{ $end }}; white-space: nowrap; }
    .band { height: 3mm; background: {{ $accent }}; }
    .clinic-name { font-size: 16pt; font-weight: bold; color: {{ $ink }}; }
    .doc-title { font-size: 20pt; font-weight: bold; color: {{ $accent }}; letter-spacing: 1pt; text-transform: uppercase; }
    .meta td { padding: 0.8mm 0; font-size: 8.6pt; }
    .meta .k { color: #66767c; text-align: {{ $start }}; }
    .meta .v { font-weight: bold; text-align: {{ $end }}; color: {{ $ink }}; }
    .status { background: {{ $statusBg }}; color: {{ $statusFg }}; font-weight: bold; font-size: 8pt; padding: 1.2mm 3mm; text-transform: uppercase; letter-spacing: 0.5pt; }
    .card { border: 0.25mm solid #dbe3e0; background-color: #F8FAF9; padding: 3.2mm 3.6mm; }
    table.cardt { border: 0.25mm solid #dbe3e0; background-color: #F8FAF9; }
    table.cardt > tr > td, table.cardt td.c { padding: 3.2mm 3.6mm; }
    .card-title { font-size: 7.2pt; color: {{ $accent }}; text-transform: uppercase; letter-spacing: 0.5pt; font-weight: bold; margin-bottom: 1.2mm; }
    .items th { background: {{ $ink }}; color: #ffffff; font-size: 7.8pt; font-weight: bold; padding: 2.4mm 2.2mm; text-align: {{ $start }}; text-transform: uppercase; letter-spacing: 0.3pt; }
    .items th.num { text-align: {{ $end }}; }
    .items td { padding: 2.4mm 2.2mm; border-bottom: 0.2mm solid #e3e9e7; }
    .items tr.alt td { background: #F8FAF9; }
    .totals td { padding: 1.5mm 2.6mm; font-size: 9pt; }
    .totals .sep td { border-top: 0.25mm solid #dbe3e0; }
    .totals .grand td { background: {{ $ink }}; color: #ffffff; font-weight: bold; font-size: 11pt; padding: 2.6mm; }
    .totals .due td { background: {{ $accent }}; color: #ffffff; font-weight: bold; font-size: 10.5pt; padding: 2.4mm 2.6mm; }
    .words { font-style: italic; color: {{ $ink }}; }
    .pay td { padding: 1.6mm 2.2mm; border-bottom: 0.2mm solid #e3e9e7; font-size: 8.4pt; }
    .sign-line { border-top: 0.3mm solid #8a979c; margin-top: 14mm; padding-top: 1.2mm; text-align: center; font-size: 8pt; color: #66767c; }
</style>
</head>
<body>
<div class="band"></div>

{{-- Letterhead --}}
<table style="margin-top: 5mm;">
    <tr>
        <td style="width: 58%;">
            <table>
                <tr>
                    @if($logo)
                        <td style="width: 24mm; padding-{{ $end }}: 3mm;"><img src="{{ $logo }}" style="max-height: 20mm; max-width: 22mm;"></td>
                    @endif
                    <td>
                        <div class="clinic-name">{{ $tenant->name }}</div>
                        @if($tenant->legal_name && $tenant->legal_name !== $tenant->name)<div class="muted small">{{ $tenant->legal_name }}</div>@endif
                        <div class="muted small" style="margin-top: 1mm;">
                            @if($branch){{ $branch->name }}<br>@endif
                            @if($branch?->address || $tenant->address){{ $branch?->address ?: $tenant->address }}<br>@endif
                            {{ collect([$branch?->phone ?: $tenant->phone, $branch?->email ?: $tenant->email])->filter()->join('  ·  ') }}
                        </div>
                        @if($tenant->tax_number)<div class="small" style="margin-top: 1mm;"><b>{{ $tenant->country_code === 'IN' ? 'GSTIN' : __('pdf.tax_number', [], $locale) }}:</b> {{ $tenant->tax_number }}</div>@endif
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 42%; text-align: {{ $end }};">
            <div class="doc-title">{{ $isTax ? __('pdf.tax_invoice', [], $locale) : __('pdf.invoice', [], $locale) }}</div>
            <table class="meta" style="width: 70mm; margin-{{ $start }}: auto; margin-top: 2mm;">
                <tr><td class="k">{{ __('pdf.invoice_no', [], $locale) }}</td><td class="v">{{ $invoice->number ?? __('pdf.draft', [], $locale) }}</td></tr>
                <tr><td class="k">{{ __('pdf.issue_date', [], $locale) }}</td><td class="v">{{ optional($invoice->issue_date)->format('d M Y') ?? '—' }}</td></tr>
                <tr><td class="k">{{ __('pdf.due_date', [], $locale) }}</td><td class="v">{{ optional($invoice->due_date)->format('d M Y') ?? '—' }}</td></tr>
                @if($isTax && $tenant->country_code === 'IN')
                    <tr><td class="k">{{ __('pdf.place_of_supply', [], $locale) }}</td><td class="v">{{ $branch?->city ?: '—' }}</td></tr>
                @endif
            </table>
            <div style="margin-top: 2.5mm;"><span class="status">{{ __('pdf.status.'.$status, [], $locale) }}</span></div>
        </td>
    </tr>
</table>

{{-- Parties --}}
<table style="margin-top: 6mm;">
    <tr>
        <td style="width: 50%; padding-{{ $end }}: 2mm;">
            <table class="cardt"><tr><td class="c" style="height: 26mm;">
                <div class="card-title">{{ __('pdf.bill_to', [], $locale) }}</div>
                <div style="font-size: 10.5pt; font-weight: bold; color: {{ $ink }};">{{ $invoice->patient->full_name }}</div>
                <div class="muted small">{{ __('pdf.patient_id', [], $locale) }}: {{ $invoice->patient->mrn }}@if($invoice->patient->age) · {{ __('pdf.age_years', ['age' => $invoice->patient->age], $locale) }}@endif</div>
                @if($invoice->patient->phone)<div class="small" style="margin-top: 1mm;">{{ $invoice->patient->phone }}</div>@endif
                @if($invoice->patient->email)<div class="small">{{ $invoice->patient->email }}</div>@endif
                @if($invoice->patient->address)<div class="muted small">{{ $invoice->patient->address }}</div>@endif
            </td></tr></table>
        </td>
        <td style="width: 50%; padding-{{ $start }}: 2mm;">
            <table class="cardt"><tr><td class="c" style="height: 26mm;">
                <div class="card-title">{{ __('pdf.visit_details', [], $locale) }}</div>
                <table class="small">
                    @if($practitioners->isNotEmpty())
                        <tr><td class="muted" style="width: 38%;">{{ __('pdf.physiotherapist', [], $locale) }}</td><td>{{ $practitioners->join(', ') }}</td></tr>
                    @endif
                    @if($visitDates->isNotEmpty())
                        <tr><td class="muted">{{ __('pdf.sessions_on', [], $locale) }}</td><td>{{ $visitDates->join(', ') }}</td></tr>
                    @endif
                    <tr><td class="muted">{{ __('pdf.branch', [], $locale) }}</td><td>{{ $branch?->name ?? '—' }}</td></tr>
                    @if($invoice->payer_type === 'insurance' && $invoice->insurancePolicy)
                        <tr><td class="muted">{{ __('pdf.insurer', [], $locale) }}</td><td>{{ $invoice->insurancePolicy->payer->name }} · {{ $invoice->insurancePolicy->policy_number }}</td></tr>
                    @endif
                </table>
            </td></tr></table>
        </td>
    </tr>
</table>

{{-- Line items --}}
<table class="items" style="margin-top: 6mm;">
    <thead>
    <tr>
        <th style="width: 6%;">#</th>
        <th style="width: 40%;">{{ __('pdf.description', [], $locale) }}</th>
        <th class="num" style="width: 7%;">{{ __('pdf.qty', [], $locale) }}</th>
        <th class="num" style="width: 13%;">{{ __('pdf.rate', [], $locale) }}</th>
        <th class="num" style="width: 11%;">{{ __('pdf.discount', [], $locale) }}</th>
        @if($isTax)<th class="num" style="width: 9%;">{{ __('pdf.tax', [], $locale) }}</th>@endif
        <th class="num" style="width: 14%;">{{ __('pdf.amount', [], $locale) }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach($invoice->items as $i => $item)
        <tr class="{{ $i % 2 ? 'alt' : '' }}">
            <td class="muted">{{ $i + 1 }}</td>
            <td>
                <div style="font-weight: bold; color: {{ $ink }};">{{ $item->description }}</div>
                @php($sub = collect([$item->service?->code ? ($tenant->country_code === 'IN' && ctype_digit((string) $item->service->code) ? 'SAC ' : '').$item->service->code : null, $item->appointment?->practitioner?->name])->filter()->join(' · '))
                @if($sub)<div class="muted small">{{ $sub }}</div>@endif
            </td>
            <td class="num">{{ $item->quantity }}</td>
            <td class="num">{{ $money($item->unit_price_minor) }}</td>
            <td class="num">{{ $item->discount_minor ? $money($item->discount_minor) : '—' }}</td>
            @if($isTax)<td class="num">{{ $item->tax_rate_bp ? rtrim(rtrim(number_format($item->tax_rate_bp / 100, 2), '0'), '.').'%' : '—' }}</td>@endif
            <td class="num" style="font-weight: bold;">{{ $money($item->total_minor) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

{{-- Summary --}}
<table style="margin-top: 5mm;">
    <tr>
        <td style="width: 56%; padding-{{ $end }}: 5mm;">
            <div class="label">{{ __('pdf.amount_in_words', [], $locale) }}</div>
            <div class="words" style="margin-top: 1mm;">{{ $words }}</div>

            @if($upi || $bank)
                <table style="margin-top: 5mm;" class="cardt">
                    <tr>
                        @if($upi)
                            <td class="c" style="width: 30mm;">
                                <barcode code="{{ $upi }}" type="QR" size="0.95" error="M" disableborder="1" />
                            </td>
                        @endif
                        <td class="c">
                            <div class="card-title">{{ __('pdf.how_to_pay', [], $locale) }}</div>
                            @if($upi)
                                <div class="small"><b>UPI:</b> {{ $tenant->setting('invoice.upi_id') }}</div>
                                <div class="muted small">{{ __('pdf.scan_to_pay', [], $locale) }}</div>
                            @endif
                            @if($bank)<div class="small" style="margin-top: 1.5mm;">{!! nl2br(e($bank)) !!}</div>@endif
                        </td>
                    </tr>
                </table>
            @endif

            @if($invoice->notes)
                <div class="label" style="margin-top: 5mm;">{{ __('pdf.notes', [], $locale) }}</div>
                <div class="small" style="margin-top: 1mm;">{!! nl2br(e($invoice->notes)) !!}</div>
            @endif
        </td>
        <td style="width: 44%;">
            <table class="totals">
                <tr><td>{{ __('pdf.subtotal', [], $locale) }}</td><td class="num">{{ $money($invoice->subtotal_minor) }}</td></tr>
                @if($invoice->discount_minor)<tr><td>{{ __('pdf.discount', [], $locale) }}</td><td class="num">− {{ $money($invoice->discount_minor) }}</td></tr>@endif
                @foreach($taxLines as [$label, $amount])
                    <tr><td>{{ $label }}</td><td class="num">{{ $money($amount) }}</td></tr>
                @endforeach
                <tr class="grand"><td>{{ __('pdf.total', [], $locale) }}</td><td class="num">{{ $money($invoice->total_minor) }}</td></tr>
                @if($invoice->amount_credited_minor)<tr><td>{{ __('pdf.credited', [], $locale) }}</td><td class="num">− {{ $money($invoice->amount_credited_minor) }}</td></tr>@endif
                <tr class="sep"><td>{{ __('pdf.amount_paid', [], $locale) }}</td><td class="num">− {{ $money($invoice->amount_paid_minor) }}</td></tr>
                <tr class="due"><td>{{ __('pdf.balance', [], $locale) }}</td><td class="num">{{ $money(max(0, $invoice->balance_minor)) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- Payments received --}}
@if($invoice->payments->isNotEmpty())
    <div class="label" style="margin-top: 7mm;">{{ __('pdf.payments', [], $locale) }}</div>
    <table class="pay" style="margin-top: 1.5mm;">
        @foreach($invoice->payments as $payment)
            <tr>
                <td style="width: 24%;">{{ $payment->received_at->timezone($timezone)->format('d M Y') }}</td>
                <td style="width: 26%;">{{ __('pdf.methods.'.$payment->method->value, [], $locale) }}@if($payment->type === 'refund') ({{ __('pdf.refund', [], $locale) }})@endif</td>
                <td class="muted">{{ $payment->reference ?? '' }}</td>
                <td class="num" style="width: 22%;">{{ $money($payment->signedAmount()) }}</td>
            </tr>
        @endforeach
    </table>
@endif

{{-- Terms and signature --}}
<table style="margin-top: 8mm;">
    <tr>
        <td style="width: 60%; padding-{{ $end }}: 6mm;">
            @if($terms)
                <div class="label">{{ __('pdf.terms', [], $locale) }}</div>
                <div class="muted small" style="margin-top: 1mm;">{!! nl2br(e($terms)) !!}</div>
            @endif
            <div class="muted small" style="margin-top: 3mm;">{{ __('pdf.computer_generated', [], $locale) }}</div>
        </td>
        <td style="width: 40%; text-align: center;">
            <table>
                <tr><td style="text-align: center; font-weight: bold; color: {{ $ink }}; font-size: 8pt; height: 16mm;">{{ __('pdf.for_clinic', ['clinic' => $tenant->name], $locale) }}</td></tr>
                <tr><td style="text-align: center; border-top: 0.3mm solid #8a979c; padding-top: 1.2mm; font-size: 8pt; color: #66767c;">{{ $tenant->setting('invoice.signatory') ?: __('pdf.authorised_signatory', [], $locale) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div style="margin-top: 8mm; text-align: center; color: {{ $accent }}; font-weight: bold; font-size: 9.5pt;">
    {{ $tenant->setting('invoice.footer_note') ?: __('pdf.thank_you', [], $locale) }}
</div>
</body>
</html>
