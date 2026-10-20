<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recurrence is stored as local wall-clock time + IANA timezone so schedules
        // survive timezone-rule changes (UTC instants are re-derived when tzdata changes).
        Schema::create('appointment_series', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('practitioner_id', 'users');
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('service_id', 'services', nullable: true);
            $table->tenantForeign('room_id', 'rooms', nullable: true);
            $table->time('local_start_time');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('timezone', 64);
            $table->string('frequency', 16)->default('weekly');
            $table->unsignedTinyInteger('interval')->default(1);
            $table->json('by_weekdays');
            $table->date('starts_on');
            $table->date('until_on')->nullable();
            $table->unsignedSmallInteger('occurrences')->nullable();
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->datetimes();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('practitioner_id', 'users');
            $table->tenantForeign('room_id', 'rooms', nullable: true);
            $table->tenantForeign('service_id', 'services', nullable: true);
            $table->tenantForeign('series_id', 'appointment_series', nullable: true);
            $table->string('type', 16)->default('clinic'); // clinic | home_visit | telehealth
            $table->string('status', 16)->default('scheduled');
            // UTC instants used for overlap checks and sorting.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // Practitioner busy window = [starts_at - travel buffer, ends_at + travel buffer].
            $table->dateTime('blocked_from');
            $table->dateTime('blocked_until');
            // Source of truth for wall-clock time.
            $table->dateTime('local_starts_at');
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('travel_buffer_minutes')->default(0);
            $table->text('home_visit_address')->nullable(); // encrypted
            $table->text('notes')->nullable();
            $table->string('booked_via', 16)->default('staff'); // staff | portal
            $table->string('cancellation_reason')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->datetimes();

            $table->index(['tenant_id', 'practitioner_id', 'blocked_from', 'blocked_until'], 'appointments_practitioner_window_idx');
            $table->index(['tenant_id', 'room_id', 'starts_at', 'ends_at'], 'appointments_room_window_idx');
            $table->index(['tenant_id', 'patient_id', 'starts_at', 'ends_at'], 'appointments_patient_window_idx');
            $table->index(['tenant_id', 'branch_id', 'starts_at']);
            $table->index(['tenant_id', 'status', 'starts_at']);
        });

        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('appointment_id', 'appointments');
            $table->tenantForeign('practitioner_id', 'users');
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->datetimes();
            $table->unique(['tenant_id', 'appointment_id']);
        });
    }

    public function down(): void
    {
        foreach (['feedback', 'appointments', 'appointment_series'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
