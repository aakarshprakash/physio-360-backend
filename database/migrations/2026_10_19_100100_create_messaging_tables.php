<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reusable texts with {{placeholders}}; one per purpose is the clinic default.
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('name', 120);
            $table->string('purpose', 32);   // appointment_reminder | payment_reminder | general | birthday | follow_up
            $table->string('channel', 16);   // sms | whatsapp | email
            $table->string('subject', 190)->nullable(); // email only
            $table->text('body');
            $table->string('locale', 8)->default('en');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
            $table->index(['tenant_id', 'purpose', 'channel']);
        });

        // Outbound message log. Recipient and body can contain PHI: encrypted at rest.
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('branch_id', 'branches', nullable: true);
            $table->tenantForeign('patient_id', 'patients', nullable: true);
            $table->tenantForeign('invoice_id', 'invoices', nullable: true);
            $table->tenantForeign('appointment_id', 'appointments', nullable: true);
            $table->tenantForeign('template_id', 'message_templates', nullable: true);
            $table->string('channel', 16);               // sms | whatsapp | email
            $table->string('purpose', 32)->default('general');
            $table->text('recipient');                    // encrypted phone / email
            $table->string('subject', 190)->nullable();
            $table->text('body');                         // encrypted
            $table->string('status', 16)->default('queued'); // queued | sent | delivered | failed | manual
            $table->string('provider', 24)->nullable();   // twilio | mail | manual | log
            $table->string('provider_message_id', 128)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->tenantForeign('sent_by', 'users', nullable: true); // null = automatic
            $table->dateTime('sent_at')->nullable();
            $table->datetimes();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'patient_id', 'created_at']);
            $table->index(['tenant_id', 'invoice_id', 'purpose']);
            $table->index(['provider', 'provider_message_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            // The log is evidence of what was sent: content cannot be rewritten, only delivery state.
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER messages_content_frozen BEFORE UPDATE ON messages FOR EACH ROW
                BEGIN
                    IF NOT (NEW.body <=> OLD.body) OR NOT (NEW.recipient <=> OLD.recipient) OR NEW.channel <> OLD.channel
                        OR NOT (NEW.patient_id <=> OLD.patient_id) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Message content is immutable';
                    END IF;
                END
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS messages_content_frozen');
        }
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_templates');
    }
};
