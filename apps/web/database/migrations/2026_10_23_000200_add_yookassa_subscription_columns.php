<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('provider_payment_method_id')->nullable()->after('stripe_price_id');
            $table->string('scheduled_plan', 32)->nullable()->after('plan');
            $table->string('scheduled_interval', 16)->nullable()->after('interval');
            $table->timestampTz('last_charge_attempt_at')->nullable()->after('payment_failed_at');
            $table->index(['gateway', 'status', 'current_period_end']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['gateway', 'status', 'current_period_end']);
            $table->dropColumn(['provider_payment_method_id', 'scheduled_plan', 'scheduled_interval', 'last_charge_attempt_at']);
        });
    }
};
