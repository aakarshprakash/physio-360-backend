<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Employee profile (HR record) + credential hygiene.
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_code', 32)->nullable()->after('name');
            $table->string('gender', 16)->nullable()->after('phone');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->date('joined_on')->nullable()->after('date_of_birth');
            $table->string('employment_type', 16)->nullable()->after('joined_on'); // full_time | part_time | contract | visiting | intern
            $table->string('qualification', 190)->nullable()->after('specialization');
            $table->string('registration_number', 64)->nullable()->after('qualification'); // professional council registration
            $table->text('address')->nullable()->after('registration_number');
            $table->string('emergency_contact_name', 120)->nullable()->after('address');
            $table->string('emergency_contact_phone', 32)->nullable()->after('emergency_contact_name');
            $table->text('hr_notes')->nullable()->after('emergency_contact_phone');
            $table->boolean('geofence_exempt')->default(false)->after('is_active'); // field / home-visit staff
            $table->boolean('online_bookable')->default(true)->after('is_practitioner'); // listed on the public booking page
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->dateTime('password_changed_at')->nullable()->after('must_change_password');
            $table->index(['tenant_id', 'employee_code']);
        });

        // Branch location for geofenced clock-in.
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('city');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedSmallInteger('geofence_radius_m')->default(150)->after('longitude');
        });

        // Where each clock-in / clock-out happened.
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->decimal('check_in_lat', 10, 7)->nullable()->after('check_in_at');
            $table->decimal('check_in_lng', 10, 7)->nullable()->after('check_in_lat');
            $table->unsignedInteger('check_in_distance_m')->nullable()->after('check_in_lng');
            $table->decimal('check_out_lat', 10, 7)->nullable()->after('check_out_at');
            $table->decimal('check_out_lng', 10, 7)->nullable()->after('check_out_lat');
            $table->unsignedInteger('check_out_distance_m')->nullable()->after('check_out_lng');
            $table->string('device', 16)->nullable()->after('source'); // web | ios | android
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('notes');
        });

        // Letterhead logo for invoices and the public booking page.
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('category');
            $table->boolean('online_bookable')->default(true)->after('is_active');
        });

        // Appointment requests from the public booking page / website widget / WhatsApp link.
        Schema::create('booking_requests', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('reference', 16);
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('service_id', 'services', nullable: true);
            $table->tenantForeign('practitioner_id', 'users', nullable: true); // requested; null = any
            $table->dateTime('requested_start');                                 // UTC
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('name', 128);
            $table->text('phone');
            $table->char('phone_index', 64)->nullable();
            $table->text('email')->nullable();
            $table->string('gender', 16)->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('message', 1000)->nullable();
            $table->string('source', 24)->default('link'); // link | widget | whatsapp | qr | ...
            $table->string('status', 16)->default('pending'); // pending | confirmed | declined | cancelled
            $table->tenantForeign('patient_id', 'patients', nullable: true);
            $table->tenantForeign('appointment_id', 'appointments', nullable: true);
            $table->tenantForeign('decided_by', 'users', nullable: true);
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->datetimes();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'status', 'requested_start']);
            $table->index(['tenant_id', 'phone_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_requests');
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn(['description', 'online_bookable']));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('logo_path'));
        Schema::table('patients', fn (Blueprint $table) => $table->dropColumn('photo_path'));
        Schema::table('attendance_records', fn (Blueprint $table) => $table->dropColumn(['check_in_lat', 'check_in_lng', 'check_in_distance_m', 'check_out_lat', 'check_out_lng', 'check_out_distance_m', 'device']));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude', 'geofence_radius_m']));
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'employee_code']);
            $table->dropColumn(['employee_code', 'gender', 'date_of_birth', 'joined_on', 'employment_type', 'qualification', 'registration_number', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'hr_notes', 'geofence_exempt', 'online_bookable', 'must_change_password', 'password_changed_at']);
        });
    }
};
