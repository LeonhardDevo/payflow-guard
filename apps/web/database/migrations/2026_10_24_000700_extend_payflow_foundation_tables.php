<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table): void {
            $table->timestampTz('guard_eligible_from')->nullable();
        });

        DB::table('workspace_user')
            ->whereIn('role', ['owner', 'admin', 'finance', 'approver', 'external_approver'])
            ->update(['guard_eligible_from' => DB::raw('created_at')]);

        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('pseudonymised_at')->nullable();
        });

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->smallInteger('attempt')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn(['attempt', 'next_retry_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('pseudonymised_at');
        });

        Schema::table('workspace_user', function (Blueprint $table): void {
            $table->dropColumn('guard_eligible_from');
        });
    }
};
