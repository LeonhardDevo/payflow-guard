<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->char('country', 2)->nullable()->after('currency');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('gateway', 16)->default('stripe')->after('workspace_id');
            $table->char('currency', 3)->nullable()->after('interval');
            $table->timestampTz('payment_failed_at')->nullable()->after('canceled_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['gateway', 'currency', 'payment_failed_at']);
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('country');
        });
    }
};
