<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Payment;
use App\Services\Pdf\PdfRenderer;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Printable payment receipt (A5-style slip on A4) for one payment.
 *
 * @tags Staff · Billing
 */
class ReceiptController extends Controller
{
    public function __invoke(Request $request, Payment $payment, PdfRenderer $pdf): Response
    {
        $payment->load(['invoice.patient', 'invoice.tenant', 'receiver:id,name']);
        Gate::authorize('view', $payment->invoice);

        return self::render($payment, $pdf, PdfRenderer::locale($request->input('lang')));
    }

    public static function render(Payment $payment, PdfRenderer $pdf, string $locale): Response
    {
        $invoice = $payment->invoice;
        $tenant = $invoice->tenant;
        $branch = Branch::query()->find($payment->branch_id);
        $numberLocale = $locale === 'ar' ? 'ar' : ($tenant->country_code === 'IN' ? 'en_IN' : 'en');
        $number = $payment->receipt_number ?? 'RCT-'.$payment->id;

        $content = $pdf->render('pdf.receipt', [
            'title' => 'Receipt '.$number,
            'payment' => $payment,
            'number' => $number,
            'invoice' => $invoice,
            'tenant' => $tenant,
            'branch' => $branch,
            'locale' => $locale,
            'timezone' => $branch?->effectiveTimezone() ?? $tenant->timezone,
            'money' => fn (int $minor) => Money::format($minor, $invoice->currency_code, $numberLocale),
            'generatedAt' => now($tenant->timezone)->format('d M Y H:i'),
        ], $locale);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$number.'.pdf"',
        ]);
    }
}
