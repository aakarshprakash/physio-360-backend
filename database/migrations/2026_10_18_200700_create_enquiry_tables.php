<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prospective patients (leads) before registration. Contact details are PHI-adjacent: encrypted + blind index.
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('branch_id', 'branches');
            $table->string('name', 128);
            $table->text('phone');
            $table->char('phone_index', 64)->nullable();
            $table->text('email')->nullable();
            $table->char('email_index', 64)->nullable();
            $table->string('source', 16)->default('phone'); // walk_in | phone | website | whatsapp | referral | social | other
            $table->string('interest', 255)->nullable();     // condition / reason, e.g. "Knee pain after surgery"
            $table->tenantForeign('service_id', 'services', nullable: true);
            $table->text('message')->nullable();             // encrypted
            $table->string('preferred_contact_time', 64)->nullable();
            $table->string('status', 16)->default('new');    // new | contacted | follow_up | converted | lost
            $table->string('lost_reason', 255)->nullable();
            $table->tenantForeign('assigned_to', 'users', nullable: true);
            $table->dateTime('next_follow_up_at')->nullable();
            $table->dateTime('last_contacted_at')->nullable();
            $table->tenantForeign('converted_patient_id', 'patients', nullable: true);
            $table->dateTime('converted_at')->nullable();
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->datetimes();

            $table->index(['tenant_id', 'status', 'next_follow_up_at']);
            $table->index(['tenant_id', 'phone_index']);
            $table->index(['tenant_id', 'created_at']);
        });

        // Append-only timeline of calls, notes and status changes.
        Schema::create('enquiry_activities', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('enquiry_id', 'enquiries', onDelete: 'cascade');
            $table->tenantForeign('user_id', 'users', nullable: true);
            $table->string('type', 24); // note | call | whatsapp | email | status_change | follow_up | converted
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'enquiry_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER enquiry_activities_no_update BEFORE UPDATE ON enquiry_activities FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'enquiry_activities is append-only'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS enquiry_activities_no_update');
        }
        Schema::dropIfExists('enquiry_activities');
        Schema::dropIfExists('enquiries');
    }
};
