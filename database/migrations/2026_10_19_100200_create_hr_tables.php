<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per staff member per local work date.
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('user_id', 'users');
            $table->tenantForeign('branch_id', 'branches', nullable: true);
            $table->date('work_date');
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->string('status', 16)->default('present'); // present | half_day | absent | leave | holiday
            $table->unsignedSmallInteger('minutes_worked')->nullable();
            $table->boolean('is_late')->default(false);
            $table->string('source', 16)->default('self');    // self | manual
            $table->string('notes', 255)->nullable();
            $table->tenantForeign('recorded_by', 'users', nullable: true);
            $table->datetimes();

            $table->unique(['tenant_id', 'user_id', 'work_date']);
            $table->index(['tenant_id', 'work_date']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('name', 96);
            $table->string('code', 12);
            $table->decimal('annual_quota', 5, 1)->default(0); // 0 = unlimited (e.g. loss of pay)
            $table->boolean('is_paid')->default(true);
            $table->string('color', 7)->default('#3E8E76');
            $table->boolean('is_active')->default(true);
            $table->datetimes();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('user_id', 'users');
            $table->tenantForeign('leave_type_id', 'leave_types');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('half_day', 2)->nullable(); // am | pm (single-day requests only)
            $table->decimal('days', 5, 1);
            $table->string('reason', 1000)->nullable();
            $table->string('status', 16)->default('pending'); // pending | approved | rejected | cancelled
            $table->tenantForeign('decided_by', 'users', nullable: true);
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->datetimes();

            $table->index(['tenant_id', 'user_id', 'start_date']);
            $table->index(['tenant_id', 'status']);
        });

        // Current pay structure per staff member (changes are audited).
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('user_id', 'users');
            $table->bigInteger('basic_minor')->default(0);
            $table->json('allowances')->nullable();  // [{name, amount_minor}]
            $table->json('deductions')->nullable();  // [{name, amount_minor}]
            $table->date('effective_from');
            $table->text('bank_account')->nullable(); // encrypted
            $table->text('tax_id')->nullable();       // encrypted (PAN / civil ID)
            $table->datetimes();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->char('period', 7); // YYYY-MM
            $table->string('status', 16)->default('draft'); // draft | finalized | paid
            $table->char('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->unsignedTinyInteger('working_days');
            $table->bigInteger('total_gross_minor')->default(0);
            $table->bigInteger('total_deductions_minor')->default(0);
            $table->bigInteger('total_net_minor')->default(0);
            $table->tenantForeign('created_by', 'users', nullable: true);
            $table->tenantForeign('finalized_by', 'users', nullable: true);
            $table->dateTime('finalized_at')->nullable();
            $table->tenantForeign('paid_by', 'users', nullable: true);
            $table->dateTime('paid_at')->nullable();
            $table->datetimes();
            $table->unique(['tenant_id', 'period']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('payroll_run_id', 'payroll_runs', onDelete: 'cascade');
            $table->tenantForeign('user_id', 'users');
            $table->decimal('working_days', 4, 1);
            $table->decimal('paid_days', 4, 1);
            $table->decimal('lop_days', 4, 1)->default(0);
            $table->json('earnings');    // [{name, amount_minor}]
            $table->json('deductions');  // [{name, amount_minor}]
            $table->bigInteger('gross_minor');
            $table->bigInteger('total_deductions_minor');
            $table->bigInteger('net_minor');
            $table->string('notes', 255)->nullable();
            $table->datetimes();
            $table->unique(['tenant_id', 'payroll_run_id', 'user_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            // Payslips freeze when their run is finalized (application guard mirrors this).
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER payslips_finalized_frozen BEFORE UPDATE ON payslips FOR EACH ROW
                BEGIN
                    IF (SELECT status FROM payroll_runs WHERE id = OLD.payroll_run_id) <> 'draft' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payslips of a finalized payroll run are immutable';
                    END IF;
                END
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS payslips_finalized_frozen');
        }
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('salary_structures');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('attendance_records');
    }
};
