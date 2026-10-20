@extends('pdf.layout')

@section('heading')
    <h1>{{ $payment->type === 'refund' ? __('pdf.refund_receipt', [], $locale) : __('pdf.receipt', [], $locale) }}</h1>
    <div><strong>{{ $number }}</strong></div>
    <div class="muted">{{ __('pdf.date', [], $locale) }}: {{ $payment->received_at->timezone($timezone)->format('d M Y, h:i A') }}</div>
@endsection

@section('content')
    <div class="box" style="margin-top: 6mm; text-align: center; padding: 6mm;">
        <div class="muted">{{ $payment->type === 'refund' ? __('pdf.amount_refunded', [], $locale) : __('pdf.amount_received', [], $locale) }}</div>
        <div style="font-size: 22pt; font-weight: bold; color: #142D36; margin-top: 1mm;">{{ $money($payment->amount_minor) }}</div>
    </div>

    <table class="grid" style="margin-top: 6mm;">
        <tbody>
        <tr><td style="width: 38%;" class="muted">{{ __('pdf.received_from', [], $locale) }}</td><td><strong>{{ $invoice->patient->full_name }}</strong> <span class="muted">{{ $invoice->patient->mrn }}</span></td></tr>
        <tr><td class="muted">{{ __('pdf.against_invoice', [], $locale) }}</td><td>{{ $invoice->number }} · {{ __('pdf.total', [], $locale) }} {{ $money($invoice->total_minor) }}</td></tr>
        <tr><td class="muted">{{ __('pdf.method', [], $locale) }}</td><td>{{ __('pdf.methods.'.$payment->method->value, [], $locale) }}</td></tr>
        @if($payment->reference)<tr><td class="muted">{{ __('pdf.reference', [], $locale) }}</td><td>{{ $payment->reference }}</td></tr>@endif
        <tr><td class="muted">{{ __('pdf.balance', [], $locale) }}</td><td><strong>{{ $money(max(0, $invoice->balance_minor)) }}</strong></td></tr>
        @if($payment->receiver)<tr><td class="muted">{{ __('pdf.received_by', [], $locale) }}</td><td>{{ $payment->receiver->name }}</td></tr>@endif
        </tbody>
    </table>

    <p class="footer-note">{{ __('pdf.receipt_note', [], $locale) }}</p>
@endsection
