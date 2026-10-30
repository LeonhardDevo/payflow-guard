<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan', 32);
            $table->string('status', 32);
            $table->string('interval', 8)->default('month');
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_price_id')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->bigInteger('last_event_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('processed_stripe_events', function (Blueprint $table): void {
            $table->string('event_id')->primary();
            $table->string('type', 120);
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_stripe_events');
        Schema::dropIfExists('subscriptions');
    }
};
