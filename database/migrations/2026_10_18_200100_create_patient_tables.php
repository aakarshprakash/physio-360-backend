<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Encryption policy (see docs/architecture.md):
         *  - names, DOB, gender, city: plaintext (needed for search, sorting, reporting)
         *  - phone, email: encrypted + HMAC blind index (exact-match search only)
         *  - address, emergency phone, medical history, allergies, notes: encrypted, not searchable
         */
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('mrn', 32);
            $table->string('first_name', 96);
            $table->string('last_name', 96)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->text('phone')->nullable();
            $table->char('phone_index', 64)->nullable();
            $table->text('email')->nullable();
            $table->char('email_index', 64)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 96)->nullable();
            $table->string('occupation', 128)->nullable();
            $table->string('referred_by', 128)->nullable();
            $table->string('emergency_contact_name', 128)->nullable();
            $table->text('emergency_contact_phone')->nullable();
            $table->text('medical_history')->nullable();
            $table->text('allergies')->nullable();
            $table->text('notes')->nullable();
            $table->string('preferred_language', 8)->default('en');
            $table->tenantForeign('primary_branch_id', 'branches');
            $table->tenantForeign('primary_practitioner_id', 'users', nullable: true);
            $table->string('status', 16)->default('active');
            // Patient portal credentials (separate guard)
            $table->boolean('portal_enabled')->default(false);
            $table->string('portal_password')->nullable();
            $table->char('portal_invite_token_hash', 64)->nullable();
            $table->dateTime('portal_invite_expires_at')->nullable();
            $table->dateTime('portal_last_login_at')->nullable();
            $table->rememberToken();
            $table->datetimes();
            $table->softDeletes();

            $table->unique(['tenant_id', 'mrn']);
            $table->index(['tenant_id', 'phone_index']);
            $table->index(['tenant_id', 'email_index']);
            $table->index(['tenant_id', 'first_name']);
            $table->index(['tenant_id', 'last_name']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('patient_insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_id', 'patients', onDelete: 'cascade');
            $table->tenantForeign('insurance_payer_id', 'insurance_payers');
            $table->text('policy_number');
            $table->string('member_id', 64)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->unsignedTinyInteger('coverage_percent')->default(100);
            $table->boolean('is_primary')->default(true);
            $table->datetimes();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_id', 'patients', onDelete: 'cascade');
            $table->tenantForeign('uploaded_by', 'users', nullable: true);
            $table->string('category', 32)->default('other');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 128);
            $table->unsignedInteger('size');
            $table->datetimes();
            $table->index(['tenant_id', 'patient_id']);
        });

        // Append-only. Body is snapshotted so later template edits never change what was signed.
        Schema::create('consent_signatures', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('consent_template_id', 'consent_templates');
            $table->unsignedInteger('template_version');
            $table->string('title');
            $table->longText('body_snapshot');
            $table->char('content_hash', 64);
            $table->string('signer_name', 128);
            $table->string('signer_relationship', 32)->default('self');
            $table->string('signature_path');
            $table->string('signed_via', 16); // portal | clinic
            $table->tenantForeign('witnessed_by', 'users', nullable: true);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->dateTime('signed_at');
            $table->index(['tenant_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        foreach (['consent_signatures', 'documents', 'patient_insurance_policies', 'patients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
