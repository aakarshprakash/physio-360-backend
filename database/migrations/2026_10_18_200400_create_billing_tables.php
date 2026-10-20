<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Invoice state machine: draft -> posted -> void | credited
         * Numbers are assigned only on posting (gapless per branch, from document_sequences).
         * All money columns are integer minor units in the invoice currency.
         */
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('patient_id', 'patients');
            $table->string('number', 48)->nullable();
            $table->string('status', 16)->default('draft');
            $table->char('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('amount_paid_minor')->default(0);
            $table->bigInteger('amount_credited_minor')->default(0);
            $table->string('payer_type', 16)->default('self'); // self | insurance
            $table->tenantForeign('insurance_policy_id', 'patient_insurance_policies', nullable: true);
            $table->string('claim_status', 16)->nullable();   // submitted | approved | rejected | settled
            $table->string('claim_reference', 64)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->tenantForeign('posted_by', 'users', nullable: true);
            $table->dateTime('voided_at')->nullable();
            $table->tenantForeign('voided_by', 'users', nullable: true);
            $table->string('void_reason')->nullable();
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->datetimes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'issue_date']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('invoice_id', 'invoices', onDelete: 'cascade');
            $table->tenantForeign('service_id', 'services', nullable: true);
            $table->tenantForeign('package_plan_id', 'package_plans', nullable: true);
            $table->tenantForeign('appointment_id', 'appointments', nullable: true);
            $table->string('description');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->datetimes();
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('invoice_id', 'invoices');
            $table->string('number', 48);
            $table->bigInteger('amount_minor');
            $table->string('reason');
            $table->dateTime('issued_at');
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->datetimes();
            $table->unique(['tenant_id', 'number']);
        });

        // Append-only. Refunds are separate rows with type = refund.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('invoice_id', 'invoices');
            $table->string('type', 16)->default('payment'); // payment | refund
            $table->bigInteger('amount_minor');
            $table->string('method', 24);                     // cash | card | upi | bank_transfer | insurance | online
            $table->string('reference', 128)->nullable();
            $table->string('gateway', 24)->nullable();
            $table->string('gateway_payment_id', 128)->nullable();
            $table->dateTime('received_at');
            $table->tenantForeign('received_by', 'users', nullable: true);
            $table->string('notes', 500)->nullable();
            $table->dateTime('created_at');

            $table->unique(['gateway', 'gateway_payment_id']);
            $table->index(['tenant_id', 'received_at']);
            $table->index(['tenant_id', 'invoice_id']);
        });

        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('invoice_id', 'invoices');
            $table->string('gateway', 24);
            $table->string('gateway_link_id', 128);
            $table->string('short_url');
            $table->bigInteger('amount_minor');
            $table->string('status', 16)->default('created');
            $table->dateTime('expires_at')->nullable();
            $table->datetimes();
            $table->unique(['gateway', 'gateway_link_id']);
        });

        Schema::create('patient_packages', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_id', 'patients');
            $table->tenantForeign('package_plan_id', 'package_plans');
            $table->tenantForeign('service_id', 'services', nullable: true);
            $table->tenantForeign('invoice_id', 'invoices', nullable: true);
            $table->string('name');
            $table->unsignedSmallInteger('sessions_total');
            $table->date('purchased_on');
            $table->date('expires_on');
            $table->string('status', 16)->default('active'); // active | exhausted | expired | cancelled
            $table->datetimes();
            $table->index(['tenant_id', 'patient_id', 'status']);
        });

        /*
         * Package ledger (append-only). Balance = SUM(sessions). Rules:
         *  purchase_credit  +N  when the purchasing invoice is posted
         *  session_debit    -1  when a covered appointment is completed (one per appointment)
         *  reversal_credit  +1  when a debited appointment is reverted
         *  expiry_debit     -R  when the package expires with R sessions remaining
         *  cancel_debit     -R  when the purchasing invoice is voided/credited (only if unused)
         */
        Schema::create('package_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('patient_package_id', 'patient_packages');
            $table->tenantForeign('appointment_id', 'appointments', nullable: true);
            $table->string('entry_type', 24);
            $table->smallInteger('sessions');
            $table->string('note')->nullable();
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->dateTime('created_at');
            $table->unique(['patient_package_id', 'appointment_id', 'entry_type'], 'package_ledger_once_per_appointment');
        });
    }

    public function down(): void
    {
        foreach (['package_ledger_entries', 'patient_packages', 'payment_links', 'payments', 'credit_notes', 'invoice_items', 'invoices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
