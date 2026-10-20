<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only (UPDATE/DELETE blocked by trigger). tenant_id is null for platform events.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('actor_type', 32)->nullable(); // user | patient | platform | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 128)->nullable();
            $table->string('event', 48);
            $table->string('auditable_type', 64)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'auditable_type', 'auditable_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        // Pre-aggregated daily figures (in tenant-local dates) so reports never scan raw rows.
        Schema::create('daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('branch_id', 'branches', onDelete: 'cascade');
            $table->date('date');
            $table->bigInteger('invoiced_minor')->default(0);
            $table->bigInteger('collected_minor')->default(0);
            $table->unsignedInteger('appointments_total')->default(0);
            $table->unsignedInteger('appointments_completed')->default(0);
            $table->unsignedInteger('appointments_cancelled')->default(0);
            $table->unsignedInteger('appointments_no_show')->default(0);
            $table->unsignedInteger('new_patients')->default(0);
            $table->dateTime('computed_at');
            $table->unique(['tenant_id', 'branch_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_summaries');
        Schema::dropIfExists('audit_logs');
    }
};
