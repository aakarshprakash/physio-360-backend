<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clinic -> SYSNAC support requests. Tenant-owned; the console reads across tenants explicitly.
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('number', 24);
            $table->string('subject', 190);
            $table->string('category', 16)->default('question'); // question | bug | billing | feature | training | data
            $table->string('priority', 8)->default('normal');   // low | normal | high | urgent
            $table->string('status', 24)->default('open');      // open | in_progress | waiting_on_clinic | resolved | closed
            $table->tenantForeign('created_by', 'users');
            $table->string('last_reply_by', 8)->default('clinic'); // clinic | sysnac
            $table->dateTime('last_reply_at');
            $table->dateTime('resolved_at')->nullable();
            $table->datetimes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'last_reply_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->tenantForeign('support_ticket_id', 'support_tickets', onDelete: 'cascade');
            $table->string('author_type', 8); // clinic | sysnac
            $table->tenantForeign('user_id', 'users', nullable: true);
            $table->foreignId('platform_admin_id')->nullable()->constrained('platform_admins')->restrictOnDelete();
            $table->string('author_name', 120);
            $table->text('body');
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'support_ticket_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER support_ticket_messages_no_update BEFORE UPDATE ON support_ticket_messages FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'support_ticket_messages is append-only'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS support_ticket_messages_no_update');
        }
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
