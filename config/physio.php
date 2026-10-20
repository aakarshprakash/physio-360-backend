<?php

return [

    /*
    | SYSNAC support desk shown in the clinic portal's Help & Support page.
    | New tickets and clinic replies are emailed to support.email.
    */
    'support' => [
        'email' => env('SYSNAC_SUPPORT_EMAIL', 'support@sysnac.com'),
        'phone' => env('SYSNAC_SUPPORT_PHONE', '+91 484 000 0000'),
        'whatsapp' => env('SYSNAC_SUPPORT_WHATSAPP'),
        'hours' => env('SYSNAC_SUPPORT_HOURS', 'Mon–Sat, 9:00–18:00 IST'),
        'docs_url' => env('SYSNAC_DOCS_URL'),
    ],

    /*
    | SMS / WhatsApp delivery. Each clinic stores its own Twilio credentials
    | (encrypted, Settings → Messaging). Without them, messages are logged as
    | "manual" and staff send them via a prefilled WhatsApp / SMS link.
    */
    'twilio' => [
        'base_url' => env('TWILIO_BASE_URL', 'https://api.twilio.com'),
    ],

];
