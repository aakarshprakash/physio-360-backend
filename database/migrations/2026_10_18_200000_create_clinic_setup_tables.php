<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('branch_id', 'branches');
            $table->string('name', 96);
            $table->string('type', 32)->default('treatment');
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        // Weekly working hours in the branch's local wall-clock time.
        Schema::create('working_hours', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('user_id', 'users', onDelete: 'cascade');
            $table->tenantForeign('branch_id', 'branches', onDelete: 'cascade');
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday ... 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->index(['tenant_id', 'user_id', 'weekday']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->string('category', 64)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(45);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->unsignedInteger('tax_rate_bp')->default(0); // basis points: 1800 = 18%
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('package_plans', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('name');
            $table->tenantForeign('service_id', 'services', nullable: true);
            $table->unsignedSmallInteger('sessions_count');
            $table->unsignedBigInteger('price_minor');
            $table->unsignedSmallInteger('validity_days')->default(90);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('insurance_payers', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('name');
            $table->string('type', 16)->default('insurer'); // insurer | tpa | corporate
            $table->string('code', 32)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        // Gapless counters. Rows are locked FOR UPDATE inside the transaction that consumes a number.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('type', 32);        // invoice | credit_note | patient_mrn
            $table->string('scope_key', 64);   // "tenant" or "branch:{id}"
            $table->string('prefix', 32);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(5);
            $table->datetimes();
            $table->unique(['tenant_id', 'type', 'scope_key']);
        });

        Schema::create('consent_templates', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('title');
            $table->longText('body');
            $table->string('locale', 8)->default('en');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->datetimes();
        });

        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('name');
            $table->string('body_region', 32);
            $table->string('category', 32);
            $table->string('difficulty', 16)->default('beginner');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('default_sets')->default(3);
            $table->unsignedSmallInteger('default_reps')->nullable();
            $table->unsignedSmallInteger('default_hold_seconds')->nullable();
            $table->string('media_path')->nullable();
            $table->string('video_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'body_region']);
        });
    }

    public function down(): void
    {
        foreach (['exercises', 'consent_templates', 'document_sequences', 'insurance_payers', 'package_plans', 'services', 'working_hours', 'rooms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
