<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reference data (not tenant-owned). Exponent = number of minor-unit digits (INR 2, KWD 3).
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name', 64);
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('exponent');
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->text('address')->nullable();
            $table->char('country_code', 2)->default('IN');
            $table->char('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->string('locale', 8)->default('en');
            $table->string('status', 16)->default('active');
            $table->string('plan', 32)->default('standard');
            $table->date('trial_ends_at')->nullable();
            $table->json('settings')->nullable();
            $table->text('gateway_credentials')->nullable(); // encrypted JSON
            $table->datetimes();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('name');
            $table->string('code', 12);
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city', 96)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->datetimes();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->tenantOwned();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable();
            $table->string('title', 96)->nullable();
            $table->string('specialization', 128)->nullable();
            $table->string('color', 9)->default('#3E8E76');
            $table->boolean('is_practitioner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('locale', 8)->default('en');
            $table->string('avatar_path')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->dateTime('last_login_at')->nullable();
            $table->datetimes();
            $table->index(['tenant_id', 'is_practitioner', 'is_active']);
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->tenantForeign('branch_id', 'branches', onDelete: 'cascade');
            $table->tenantForeign('user_id', 'users', onDelete: 'cascade');
            $table->primary(['branch_id', 'user_id']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->dateTime('last_login_at')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        foreach (['platform_admins', 'sessions', 'password_reset_tokens', 'branch_user', 'users', 'branches', 'tenants', 'currencies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
