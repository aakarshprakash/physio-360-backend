<?php

namespace App\Services\Messaging;

use App\Enums\MessageChannel;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Twilio Programmable Messaging for SMS and WhatsApp. Each clinic uses its
 * own account (gateway_credentials.twilio: account_sid, auth_token,
 * sms_from, whatsapp_from).
 *
 * Business-initiated WhatsApp messages outside a 24-hour customer window must
 * match a template approved by Meta; see docs/deployment.md.
 */
class TwilioGateway
{
    public const NAME = 'twilio';

    public function isConfigured(Tenant $tenant, MessageChannel $channel): bool
    {
        $c = $this->credentials($tenant);
        $from = $channel === MessageChannel::WhatsApp ? ($c['whatsapp_from'] ?? null) : ($c['sms_from'] ?? null);

        return $channel !== MessageChannel::Email && ! empty($c['account_sid']) && ! empty($c['auth_token']) && ! empty($from);
    }

    /** Returns the provider message SID; throws on a rejected request. */
    public function send(Tenant $tenant, Message $message): string
    {
        $c = $this->credentials($tenant);
        $whatsapp = $message->channel === MessageChannel::WhatsApp;
        $prefix = $whatsapp ? 'whatsapp:' : '';

        $response = Http::asForm()
            ->withBasicAuth($c['account_sid'], $c['auth_token'])
            ->acceptJson()
            ->timeout(15)
            ->post(rtrim(config('physio.twilio.base_url'), '/')."/2010-04-01/Accounts/{$c['account_sid']}/Messages.json", [
                'To' => $prefix.$message->recipient,
                'From' => $prefix.($whatsapp ? $c['whatsapp_from'] : $c['sms_from']),
                'Body' => $message->body,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Twilio %d: %s', $response->status(), $response->json('message', 'request failed')));
        }

        return (string) $response->json('sid');
    }

    private function credentials(Tenant $tenant): array
    {
        return $tenant->gateway_credentials['twilio'] ?? [];
    }
}
