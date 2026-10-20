<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gapless per-branch receipt numbers, allocated when the payment row is inserted
        // (payments are append-only, so the number can never be assigned later).
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_number', 48)->nullable()->after('type');
            $table->unique(['tenant_id', 'receipt_number']);
        });

        // Printable physiotherapy prescription: diagnosis, modalities, home exercises, advice, review date.
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('practitioner_id', 'users');
            $table->tenantForeign('assessment_id', 'assessments', nullable: true);
            $table->tenantForeign('treatment_plan_id', 'treatment_plans', nullable: true);
            $table->string('number', 48);
            $table->date('issued_on');
            $table->text('diagnosis')->nullable();    // encrypted
            $table->text('modalities')->nullable();   // encrypted JSON [{name, parameters, duration_minutes, sessions}]
            $table->text('exercises')->nullable();    // encrypted JSON [{name, dosage, notes}]
            $table->text('advice')->nullable();       // encrypted
            $table->text('precautions')->nullable();  // encrypted
            $table->date('review_on')->nullable();
            $table->datetimes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'patient_id', 'issued_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'receipt_number']);
            $table->dropColumn('receipt_number');
        });
    }
};
