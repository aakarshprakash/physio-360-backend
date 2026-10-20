<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Models\Tenant;
use App\Services\Billing\InvoiceService;
use App\Services\Payments\RazorpayGateway;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment-provider webhooks. Each clinic configures its own URL
 * (/api/webhooks/razorpay/{clinic-slug}) and secret in Razorpay.
 *
 * @tags Webhooks
 */
class WebhookController extends Controller
{
    /** @unauthenticated */
    public function razorpay(Request $request, string $slug, RazorpayGateway $gateway, InvoiceService $invoices, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant || ! $gateway->verifyWebhookSignature($tenant, $request->getContent(), $request->header('X-Razorpay-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        if ($request->input('event') !== 'payment_link.paid') {
            return response()->json(['message' => 'Ignored.']);
        }

        return $context->run($tenant, function () use ($request, $invoices) {
            $linkId = $request->input('payload.payment_link.entity.id');
            $payment = $request->input('payload.payment.entity', []);
            $link = PaymentLink::query()->where('gateway', RazorpayGateway::NAME)->where('gateway_link_id', $linkId)->first();

            if (! $link || empty($payment['id'])) {
                return response()->json(['message' => 'Unknown payment link.'], 202);
            }

            $invoices->recordPayment(
                Invoice::query()->findOrFail($link->invoice_id),
                (int) $payment['amount'],
                PaymentMethod::Online,
                [
                    'gateway' => RazorpayGateway::NAME,
                    'gateway_payment_id' => $payment['id'],
                    'reference' => ($payment['method'] ?? 'online').' '.$payment['id'],
                    'received_at' => isset($payment['created_at']) ? CarbonImmutable::createFromTimestamp($payment['created_at']) : now(),
                    'notes' => 'Razorpay payment link '.$linkId,
                ],
                allowOverpayment: true, // money already received; any excess shows as a refund due
            );

            $link->update(['status' => 'paid']);

            return response()->json(['message' => 'Recorded.']);
        });
    }
}
