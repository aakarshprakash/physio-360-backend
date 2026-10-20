<?php

namespace App\Http\Resources;

use App\Enums\MessageChannel;
use App\Models\Tenant;
use App\Services\Messaging\TwilioGateway;
use App\Services\Payments\RazorpayGateway;
use Illuminate\Http\Request;

/** @mixin Tenant */
class TenantResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'legal_name' => $this->legal_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_number' => $this->tax_number,
            'address' => $this->address,
            'country_code' => $this->country_code,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'status' => $this->status->value,
            'plan' => $this->plan,
            'currency' => [
                'code' => $this->currency->code,
                'symbol' => $this->currency->symbol,
                'exponent' => $this->currency->exponent,
            ],
            'settings' => [
                'portal_booking_enabled' => (bool) $this->setting('portal_booking_enabled', true),
                'portal_min_lead_minutes' => (int) $this->setting('portal_min_lead_minutes', 120),
                'portal_cancellation_hours' => (int) $this->setting('portal_cancellation_hours', 12),
                'reminder_hours_before' => (int) $this->setting('reminder_hours_before', 24),
                'weekly_off_days' => array_map('intval', (array) $this->setting('weekly_off_days', [0])),
                /** @var list<array{date: string, name: string}> */
                'holidays' => array_values((array) $this->setting('holidays', [])),
                'attendance_grace_minutes' => (int) $this->setting('attendance_grace_minutes', 10),
                'payment_reminders' => [
                    'enabled' => (bool) $this->setting('payment_reminders.enabled', false),
                    'channel' => (string) $this->setting('payment_reminders.channel', 'email'),
                    'days_after_due' => array_map('intval', (array) $this->setting('payment_reminders.days_after_due', [1, 7, 14])),
                    'include_payment_link' => (bool) $this->setting('payment_reminders.include_payment_link', false),
                ],
            ],
            'online_payments_enabled' => app(RazorpayGateway::class)->isConfigured($this->resource),
            'messaging' => [
                'sms' => app(TwilioGateway::class)->isConfigured($this->resource, MessageChannel::Sms),
                'whatsapp' => app(TwilioGateway::class)->isConfigured($this->resource, MessageChannel::WhatsApp),
                'email' => true,
            ],
        ];
    }
}
