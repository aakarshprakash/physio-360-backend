@extends('pdf.layout')

@section('heading')
    <h1>{{ __('pdf.payslip', [], $locale) }}</h1>
    <div><strong>{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $payslip->run->period)->format('F Y') }}</strong></div>
    <div style="margin-top: 2mm;"><span class="pill">{{ __('pdf.payroll_status.'.$payslip->run->status->value, [], $locale) }}</span></div>
@endsection

@section('content')
    <table style="margin-top: 5mm;">
        <tr>
            <td style="width: 50%;" class="box">
                <div class="muted">{{ __('pdf.employee', [], $locale) }}</div>
                <strong>{{ $payslip->user->name }}</strong>
                @if($payslip->user->title)<br><span class="muted">{{ $payslip->user->title }}</span>@endif
                @if($structure?->tax_id)<br><span class="muted">{{ __('pdf.employee_tax_id', [], $locale) }}: {{ $structure->tax_id }}</span>@endif
                @if($structure?->bank_account)<br><span class="muted">{{ __('pdf.bank_account', [], $locale) }}: {{ $structure->bank_account }}</span>@endif
            </td>
            <td style="width: 50%;" class="box">
                <div class="muted">{{ __('pdf.attendance', [], $locale) }}</div>
                {{ __('pdf.working_days', [], $locale) }}: <strong>{{ rtrim(rtrim(number_format($payslip->working_days, 1), '0'), '.') }}</strong><br>
                {{ __('pdf.paid_days', [], $locale) }}: <strong>{{ rtrim(rtrim(number_format($payslip->paid_days, 1), '0'), '.') }}</strong><br>
                {{ __('pdf.lop_days', [], $locale) }}: <strong>{{ rtrim(rtrim(number_format($payslip->lop_days, 1), '0'), '.') }}</strong>
            </td>
        </tr>
    </table>

    <table style="margin-top: 6mm;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-{{ $rtl ? 'left' : 'right' }}: 3mm;">
                <table class="grid">
                    <thead><tr><th>{{ __('pdf.earnings', [], $locale) }}</th><th class="num">{{ __('pdf.amount', [], $locale) }}</th></tr></thead>
                    <tbody>
                    @foreach($payslip->earnings as $line)
                        <tr><td>{{ $line['name'] }}</td><td class="num">{{ $money($line['amount_minor']) }}</td></tr>
                    @endforeach
                    <tr><td><strong>{{ __('pdf.gross', [], $locale) }}</strong></td><td class="num"><strong>{{ $money($payslip->gross_minor) }}</strong></td></tr>
                    </tbody>
                </table>
            </td>
            <td style="width: 50%; vertical-align: top;">
                <table class="grid">
                    <thead><tr><th>{{ __('pdf.deductions', [], $locale) }}</th><th class="num">{{ __('pdf.amount', [], $locale) }}</th></tr></thead>
                    <tbody>
                    @forelse($payslip->deductions as $line)
                        <tr><td>{{ $line['name'] }}</td><td class="num">{{ $money($line['amount_minor']) }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">—</td></tr>
                    @endforelse
                    <tr><td><strong>{{ __('pdf.total_deductions', [], $locale) }}</strong></td><td class="num"><strong>{{ $money($payslip->total_deductions_minor) }}</strong></td></tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <div class="box" style="margin-top: 6mm;">
        <table><tr>
            <td style="font-size: 12pt;"><strong>{{ __('pdf.net_pay', [], $locale) }}</strong></td>
            <td class="num" style="font-size: 14pt; color: #142D36;"><strong>{{ $money($payslip->net_minor) }}</strong></td>
        </tr></table>
    </div>

    @if($payslip->notes)<p class="muted">{{ $payslip->notes }}</p>@endif
    <p class="footer-note">{{ __('pdf.payslip_note', [], $locale) }}</p>
@endsection
