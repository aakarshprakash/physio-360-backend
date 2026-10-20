<?php

/*
| Starter message templates for new clinics. {{placeholders}} are listed in
| App\Services\Messaging\MessageComposer::PLACEHOLDERS. English is always
| seeded; Malayalam for Indian clinics and Arabic for GCC clinics.
*/

$t = fn (string $locale, string $purpose, string $channel, string $name, string $body, ?string $subject = null) => compact('locale', 'purpose', 'channel', 'name', 'body', 'subject');

return [
    // ---- English -----------------------------------------------------------
    $t('en', 'appointment_reminder', 'whatsapp', 'Appointment reminder',
        'Hello {{patient_first_name}}, this is a reminder of your physiotherapy appointment at {{clinic_name}} on {{appointment_date}} at {{appointment_time}} with {{practitioner_name}}. Reply or call {{clinic_phone}} to reschedule.'),
    $t('en', 'appointment_reminder', 'sms', 'Appointment reminder (SMS)',
        '{{clinic_name}}: Reminder of your appointment on {{appointment_date}} at {{appointment_time}}. Call {{clinic_phone}} to reschedule.'),
    $t('en', 'payment_reminder', 'whatsapp', 'Payment reminder',
        "Dear {{patient_name}}, a balance of {{amount_due}} is pending on invoice {{invoice_number}} from {{clinic_name}}. {{payment_link}}\nThank you for choosing us for your care."),
    $t('en', 'payment_reminder', 'sms', 'Payment reminder (SMS)',
        '{{clinic_name}}: {{amount_due}} is due on invoice {{invoice_number}}. {{payment_link}}'),
    $t('en', 'payment_reminder', 'email', 'Payment reminder (email)',
        "Dear {{patient_name}},\n\nThis is a friendly reminder that {{amount_due}} is outstanding on invoice {{invoice_number}} (due {{due_date}}).\n\n{{payment_link}}\n\nIf you have already paid, please ignore this message.\n\nWarm regards,\n{{clinic_name}}\n{{clinic_phone}}",
        'Payment reminder: invoice {{invoice_number}}'),
    $t('en', 'follow_up', 'whatsapp', 'Missed-visit follow-up',
        'Hello {{patient_first_name}}, we noticed you have not visited {{clinic_name}} recently. Continuing your treatment helps you recover fully. Reply to book your next session.'),
    $t('en', 'birthday', 'whatsapp', 'Birthday greeting',
        'Happy birthday, {{patient_first_name}}! Wishing you a year of good health and easy movement. – Team {{clinic_name}}'),

    // ---- Malayalam ---------------------------------------------------------
    $t('ml', 'appointment_reminder', 'whatsapp', 'അപ്പോയിന്റ്മെന്റ് ഓർമ്മപ്പെടുത്തൽ',
        'നമസ്കാരം {{patient_first_name}}, {{clinic_name}}-ൽ {{appointment_date}} {{appointment_time}}-ന് {{practitioner_name}}-മായുള്ള നിങ്ങളുടെ ഫിസിയോതെറാപ്പി അപ്പോയിന്റ്മെന്റ് ഓർമ്മിപ്പിക്കുന്നു. സമയം മാറ്റാൻ {{clinic_phone}} എന്ന നമ്പറിൽ ബന്ധപ്പെടുക.'),
    $t('ml', 'appointment_reminder', 'sms', 'അപ്പോയിന്റ്മെന്റ് ഓർമ്മപ്പെടുത്തൽ (SMS)',
        '{{clinic_name}}: {{appointment_date}} {{appointment_time}}-ന് നിങ്ങളുടെ അപ്പോയിന്റ്മെന്റ് ഉണ്ട്. സമയം മാറ്റാൻ {{clinic_phone}} വിളിക്കുക.'),
    $t('ml', 'payment_reminder', 'whatsapp', 'പേയ്മെന്റ് ഓർമ്മപ്പെടുത്തൽ',
        "പ്രിയ {{patient_name}}, {{clinic_name}}-ലെ ഇൻവോയ്സ് {{invoice_number}}-ൽ {{amount_due}} അടയ്ക്കാൻ ബാക്കിയുണ്ട്. {{payment_link}}\nഞങ്ങളെ തിരഞ്ഞെടുത്തതിന് നന്ദി."),
    $t('ml', 'payment_reminder', 'sms', 'പേയ്മെന്റ് ഓർമ്മപ്പെടുത്തൽ (SMS)',
        '{{clinic_name}}: ഇൻവോയ്സ് {{invoice_number}}-ൽ {{amount_due}} അടയ്ക്കാനുണ്ട്. {{payment_link}}'),
    $t('ml', 'follow_up', 'whatsapp', 'തുടർ സന്ദർശന ഓർമ്മപ്പെടുത്തൽ',
        'നമസ്കാരം {{patient_first_name}}, കുറച്ചു ദിവസമായി നിങ്ങൾ {{clinic_name}}-ൽ വന്നിട്ടില്ല. ചികിത്സ തുടരുന്നത് പൂർണ്ണമായി സുഖം പ്രാപിക്കാൻ സഹായിക്കും. അടുത്ത സെഷൻ ബുക്ക് ചെയ്യാൻ മറുപടി അയയ്ക്കുക.'),
    $t('ml', 'birthday', 'whatsapp', 'ജന്മദിനാശംസ',
        'ജന്മദിനാശംസകൾ, {{patient_first_name}}! ആരോഗ്യവും സന്തോഷവും നിറഞ്ഞ ഒരു വർഷം ആശംസിക്കുന്നു. – {{clinic_name}}'),

    // ---- Arabic --------------------------------------------------------------
    $t('ar', 'appointment_reminder', 'whatsapp', 'تذكير بالموعد',
        'مرحبًا {{patient_first_name}}، نذكرك بموعد العلاج الطبيعي في {{clinic_name}} يوم {{appointment_date}} الساعة {{appointment_time}} مع {{practitioner_name}}. لتغيير الموعد اتصل على {{clinic_phone}}.'),
    $t('ar', 'appointment_reminder', 'sms', 'تذكير بالموعد (SMS)',
        '{{clinic_name}}: تذكير بموعدك يوم {{appointment_date}} الساعة {{appointment_time}}. للتغيير اتصل على {{clinic_phone}}.'),
    $t('ar', 'payment_reminder', 'whatsapp', 'تذكير بالدفع',
        "عزيزي {{patient_name}}، يوجد مبلغ مستحق قدره {{amount_due}} على الفاتورة {{invoice_number}} من {{clinic_name}}. {{payment_link}}\nشكرًا لثقتك بنا."),
    $t('ar', 'payment_reminder', 'sms', 'تذكير بالدفع (SMS)',
        '{{clinic_name}}: مبلغ {{amount_due}} مستحق على الفاتورة {{invoice_number}}. {{payment_link}}'),
    $t('ar', 'follow_up', 'whatsapp', 'متابعة الزيارات',
        'مرحبًا {{patient_first_name}}، لاحظنا أنك لم تزر {{clinic_name}} مؤخرًا. استمرارك في العلاج يساعدك على التعافي التام. رد على هذه الرسالة لحجز جلستك القادمة.'),
    $t('ar', 'birthday', 'whatsapp', 'تهنئة بعيد الميلاد',
        'عيد ميلاد سعيد يا {{patient_first_name}}! نتمنى لك عامًا مليئًا بالصحة والحركة. – فريق {{clinic_name}}'),
];
