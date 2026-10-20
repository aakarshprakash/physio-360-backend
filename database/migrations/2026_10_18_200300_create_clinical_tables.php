<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('practitioner_id', 'users');
            $table->tenantForeign('appointment_id', 'appointments', nullable: true);
            $table->dateTime('assessed_at');
            $table->string('chief_complaint');
            $table->text('history')->nullable();        // encrypted
            $table->unsignedTinyInteger('pain_score')->nullable();
            $table->string('pain_location', 128)->nullable();
            $table->text('findings')->nullable();       // encrypted JSON (ROM, strength, special tests)
            $table->text('diagnosis')->nullable();      // encrypted
            $table->text('goals')->nullable();          // encrypted
            $table->string('status', 16)->default('draft');
            $table->dateTime('signed_at')->nullable();
            $table->tenantForeign('signed_by', 'users', nullable: true);
            $table->datetimes();
            $table->index(['tenant_id', 'patient_id', 'assessed_at']);
        });

        // SOAP notes. Immutable once signed (model guard + DB trigger); corrections are addenda.
        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('appointment_id', 'appointments', nullable: true);
            $table->tenantForeign('author_id', 'users');
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->unsignedTinyInteger('pain_score')->nullable();
            $table->string('status', 16)->default('draft');
            $table->dateTime('signed_at')->nullable();
            $table->tenantForeign('signed_by', 'users', nullable: true);
            $table->char('content_hash', 64)->nullable();
            $table->datetimes();
            $table->index(['tenant_id', 'patient_id', 'created_at']);
        });

        Schema::create('clinical_note_addenda', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('clinical_note_id', 'clinical_notes');
            $table->tenantForeign('author_id', 'users');
            $table->text('body');
            $table->dateTime('created_at');
        });

        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('practitioner_id', 'users');
            $table->string('title');
            $table->text('diagnosis')->nullable();
            $table->text('goals')->nullable();
            $table->date('start_date');
            $table->unsignedSmallInteger('duration_weeks')->default(6);
            $table->unsignedTinyInteger('frequency_per_week')->default(2);
            $table->string('status', 16)->default('active'); // draft | active | completed | cancelled
            $table->text('notes')->nullable();
            $table->datetimes();
            $table->index(['tenant_id', 'patient_id', 'status']);
        });

        Schema::create('treatment_plan_exercises', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('treatment_plan_id', 'treatment_plans', onDelete: 'cascade');
            $table->tenantForeign('exercise_id', 'exercises');
            $table->unsignedSmallInteger('sets')->default(3);
            $table->unsignedSmallInteger('reps')->nullable();
            $table->unsignedSmallInteger('hold_seconds')->nullable();
            $table->string('frequency', 64)->default('Daily');
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->datetimes();
        });

        // Home-exercise adherence logged by the patient in the PWA.
        Schema::create('exercise_logs', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('treatment_plan_exercise_id', 'treatment_plan_exercises', onDelete: 'cascade');
            $table->date('performed_on');
            $table->unsignedTinyInteger('pain_score')->nullable();
            $table->string('notes', 500)->nullable();
            $table->datetimes();
            $table->unique(['treatment_plan_exercise_id', 'performed_on'], 'exercise_logs_once_per_day');
        });

        Schema::create('progress_entries', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('treatment_plan_id', 'treatment_plans', nullable: true);
            $table->date('recorded_on');
            $table->unsignedTinyInteger('pain_score')->nullable();      // 0-10 NPRS
            $table->unsignedTinyInteger('function_score')->nullable();  // 0-100
            $table->string('measure', 96)->nullable();                  // e.g. "Knee flexion ROM"
            $table->decimal('value', 8, 2)->nullable();
            $table->string('unit', 16)->nullable();
            $table->string('notes', 500)->nullable();
            $table->tenantForeign('recorded_by', 'users', nullable: true);
            $table->datetimes();
            $table->index(['tenant_id', 'patient_id', 'recorded_on']);
        });
    }

    public function down(): void
    {
        foreach (['progress_entries', 'exercise_logs', 'treatment_plan_exercises', 'treatment_plans', 'clinical_note_addenda', 'clinical_notes', 'assessments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
