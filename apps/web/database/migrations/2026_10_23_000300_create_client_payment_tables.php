<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('driver', 16);
            $table->text('credentials');
            $table->string('webhook_endpoint_id')->nullable();
            $table->timestampTz('connected_at')->nullable();
            $table->timestampsTz();
            $table->unique(['workspace_id', 'driver']);
        });

        Schema::create('payment_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('driver', 16);
            $table->string('reference', 120);
            $table->string('provider_id');
            $table->string('provider_payment_id')->nullable();
            $table->text('url');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 16)->default('pending');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->unsignedBigInteger('last_event_at')->nullable();
            $table->timestampsTz();
            $table->unique(['driver', 'provider_id']);
            $table->index(['workspace_id', 'reference']);
            $table->index(['status', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
        Schema::dropIfExists('payment_accounts');
    }
};
