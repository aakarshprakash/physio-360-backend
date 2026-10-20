<?php

namespace App\Services\Payments;

use App\Exceptions\DomainRuleViolation;
use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Razorpay Payment Links. Each clinic uses its own Razorpay account; the
 * credentials live encrypted on the tenant (gateway_credentials.razorpay).
 * Settlement is confirmed only by the signed webhook, never by the browser.
 */
class RazorpayGateway
{
    public const NAME = 'razorpay';

    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function isConfigured(Tenant $tenant): bool
    {
        $c = $this->credentials($tenant);

        return ! empty($c['key_id']) && ! empty($c['key_secret']) && ! empty($c['webhook_secret']);
    }

    public function createPaymentLink(Tenant $tenant, Invoice $invoice, int $amountMinor, ?string $callbackUrl = null): PaymentLink
    {
        if (! $this->isConfigured($tenant)) {
            throw new DomainRuleViolation('Online payments are not configured for this clinic.', 'gateway_not_configured');
        }

        $credentials = $this->credentials($tenant);
        $patient = $invoice->patient;
        $expiresAt = CarbonImmutable::now()->addDays(7);

        $response = Http::withBasicAuth($credentials['key_id'], $credentials['key_secret'])
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 500, throw: false)
            ->post(self::BASE_URL.'/payment_links', array_filter([
                'amount' => $amountMinor,
                'currency' => $invoice->currency_code,
                'accept_partial' => false,
                'reference_id' => $invoice->number.'-'.now()->format('His'),
                'description' => 'Invoice '.$invoice->number.' - '.$tenant->name,
                'expire_by' => $expiresAt->getTimestamp(),
                'customer' => array_filter([
                    'name' => $patient->full_name,
                    'contact' => $patient->phone,
                    'email' => $patient->email,
                ]),
                'notify' => ['sms' => false, 'email' => false],
                'reminder_enable' => false,
                'callback_url' => $callbackUrl,
                'callback_method' => $callbackUrl ? 'get' : null,
                'notes' => ['tenant_id' => (string) $tenant->id, 'invoice_id' => (string) $invoice->id],
            ], fn ($v) => $v !== null));

        if ($response->failed()) {
            report(new \RuntimeException('Razorpay payment link failed: '.$response->status().' '.$response->body()));
            throw new DomainRuleViolation('The payment provider could not create a payment link. Please try again.', 'gateway_error');
        }

        return PaymentLink::query()->create([
            'invoice_id' => $invoice->id,
            'gateway' => self::NAME,
            'gateway_link_id' => $response->json('id'),
            'short_url' => $response->json('short_url'),
            'amount_minor' => $amountMinor,
            'status' => $response->json('status', 'created'),
            'expires_at' => $expiresAt,
        ]);
    }

    /** Constant-time HMAC-SHA256 check of the raw request body. */
    public function verifyWebhookSignature(Tenant $tenant, string $payload, ?string $signature): bool
    {
        $secret = $this->credentials($tenant)['webhook_secret'] ?? null;

        if (! $secret || ! $signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    private function credentials(Tenant $tenant): array
    {
        return $tenant->gateway_credentials['razorpay'] ?? [];
    }
}
