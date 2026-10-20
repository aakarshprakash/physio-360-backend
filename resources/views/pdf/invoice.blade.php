@extends('pdf.layout')

@section('heading')
    <h1>{{ $invoice->tax_minor > 0 ? __('pdf.tax_invoice', [], $locale) : __('pdf.invoice', [], $locale) }}</h1>
    <div><strong>{{ $invoice->number ?? __('pdf.draft', [], $locale) }}</strong></div>
    <div class="muted">{{ __('pdf.issue_date', [], $locale) }}: {{ optional($invoice->issue_date)->format('d M Y') ?? '—' }}</div>
    <div class="muted">{{ __('pdf.due_date', [], $locale) }}: {{ optional($invoice->due_date)->format('d M Y') ?? '—' }}</div>
    <div style="margin-top: 2mm;"><span class="pill">{{ __('pdf.status.'.($invoice->payment_status ?? $invoice->status->value), [], $locale) }}</span></div>
@endsection

@section('content')
    <h2>{{ __('pdf.bill_to', [], $locale) }}</h2>
    <div class="box">
        <strong>{{ $invoice->patient->full_name }}</strong> &nbsp; <span class="muted">{{ $invoice->patient->mrn }}</span><br>
        @if($invoice->patient->phone){{ $invoice->patient->phone }}<br>@endif
        @if($invoice->patient->address)<span class="muted">{{ $invoice->patient->address }}</span>@endif
        @if($invoice->payer_type === 'insurance' && $invoice->insurancePolicy)
            <br><span class="muted">{{ __('pdf.insurer', [], $locale) }}: {{ $invoice->insurancePolicy->payer->name }} · {{ $invoice->insurancePolicy->policy_number }}</span>
        @endif
    </div>

    <h2>{{ __('pdf.items', [], $locale) }}</h2>
    <table class="grid">
        <thead>
        <tr>
            <th style="width: 46%;">{{ __('pdf.description', [], $locale) }}</th>
            <th class="num">{{ __('pdf.qty', [], $locale) }}</th>
            <th class="num">{{ __('pdf.unit_price', [], $locale) }}</th>
            <th class="num">{{ __('pdf.discount', [], $locale) }}</th>
            <th class="num">{{ __('pdf.tax', [], $locale) }}</th>
            <th class="num">{{ __('pdf.amount', [], $locale) }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="num">{{ $item->quantity }}</td>
                <td class="num">{{ $money($item->unit_price_minor) }}</td>
                <td class="num">{{ $item->discount_minor ? $money($item->discount_minor) : '—' }}</td>
                <td class="num">{{ $item->tax_rate_bp ? rtrim(rtrim(number_format($item->tax_rate_bp / 100, 2), '0'), '.').'%' : '—' }}</td>
                <td class="num">{{ $money($item->total_minor) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals" style="width: 55%; margin-{{ $rtl ? 'right' : 'left' }}: 45%; margin-top: 3mm;">
        <tr><td>{{ __('pdf.subtotal', [], $locale) }}</td><td class="num">{{ $money($invoice->subtotal_minor) }}</td></tr>
        @if($invoice->discount_minor)<tr><td>{{ __('pdf.discount', [], $locale) }}</td><td class="num">− {{ $money($invoice->discount_minor) }}</td></tr>@endif
        @if($invoice->tax_minor)<tr><td>{{ __('pdf.tax', [], $locale) }}</td><td class="num">{{ $money($invoice->tax_minor) }}</td></tr>@endif
        <tr class="grand"><td>{{ __('pdf.total', [], $locale) }}</td><td class="num">{{ $money($invoice->total_minor) }}</td></tr>
        @if($invoice->amount_credited_minor)<tr><td>{{ __('pdf.credited', [], $locale) }}</td><td class="num">− {{ $money($invoice->amount_credited_minor) }}</td></tr>@endif
        <tr><td>{{ __('pdf.paid', [], $locale) }}</td><td class="num">− {{ $money($invoice->amount_paid_minor) }}</td></tr>
        <tr class="grand"><td>{{ __('pdf.balance', [], $locale) }}</td><td class="num">{{ $money(max(0, $invoice->balance_minor)) }}</td></tr>
    </table>

    @if($invoice->payments->isNotEmpty())
        <h2>{{ __('pdf.payments', [], $locale) }}</h2>
        <table class="grid">
            <thead><tr><th>{{ __('pdf.date', [], $locale) }}</th><th>{{ __('pdf.method', [], $locale) }}</th><th>{{ __('pdf.reference', [], $locale) }}</th><th class="num">{{ __('pdf.amount', [], $locale) }}</th></tr></thead>
            <tbody>
            @foreach($invoice->payments as $payment)
                <tr>
                    <td>{{ $payment->received_at->timezone($timezone)->format('d M Y') }}</td>
                    <td>{{ __('pdf.methods.'.$payment->method->value, [], $locale) }}@if($payment->type === 'refund') ({{ __('pdf.refund', [], $locale) }})@endif</td>
                    <td>{{ $payment->reference ?? '—' }}</td>
                    <td class="num">{{ $money($payment->signedAmount()) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if($invoice->notes)
        <h2>{{ __('pdf.notes', [], $locale) }}</h2>
        <div class="box">{{ $invoice->notes }}</div>
    @endif
@endsection
