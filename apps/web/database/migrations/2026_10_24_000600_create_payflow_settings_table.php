<?php

declare(strict_types=1);

use App\Domain\Receivables\Enums\LateFeeType;
use App\Domain\Shared\Database\PgConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payflow_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('invoice_prefix', 12)->default('INV-');
            $table->integer('next_invoice_seq')->default(1);
            $table->string('payout_prefix', 12)->default('PO-');
            $table->integer('next_payout_seq')->default(1);
            $table->smallInteger('default_payment_terms_days')->default(14);
            $table->foreignId('default_reminder_policy_id')->nullable()->constrained('reminder_policies')->nullOnDelete();
            $table->time('send_window_start')->default('09:00');
            $table->time('send_window_end')->default('17:00');
            $table->boolean('business_days_only')->default(true);
            $table->smallInteger('first_contact_max_per_hour')->default(50);
            $table->string('sender_display_name', 120)->nullable();
            $table->string('reply_to_email')->nullable();
            $table->string('privacy_contact_email')->nullable();
            $table->boolean('track_invoice_views')->default(true);
            $table->string('late_fee_type', 16)->default(LateFeeType::None->value);
            $table->integer('late_fee_rate_bp')->nullable();
            $table->bigInteger('late_fee_fixed_minor')->nullable();
            $table->smallInteger('late_fee_grace_days')->default(0);
            $table->integer('late_fee_cap_bp')->nullable();
            $table->string('company_legal_name', 200)->nullable();
            $table->text('company_address')->nullable();
            $table->string('company_tax_id', 64)->nullable();
            $table->string('company_email')->nullable();
            $table->string('company_phone', 40)->nullable();
            $table->foreignId('active_receiving_details_id')->nullable()->constrained('receiving_details_versions')->nullOnDelete();
            $table->boolean('digest_enabled')->default(true);
            $table->smallInteger('digest_hour')->default(9);
            $table->jsonb('approval_thresholds')->default('{}');
            $table->smallInteger('cooling_off_hours_medium')->default(24);
            $table->smallInteger('cooling_off_hours_high')->default(48);
            $table->boolean('allow_telegram_approvals')->default(true);
            $table->smallInteger('escalate_pending_after_hours')->default(24);
            $table->smallInteger('guard_trust_delay_hours')->default(72);
            $table->boolean('require_second_factor')->default(true);
            $table->smallInteger('solo_release_delay_hours')->default(24);
            $table->boolean('onboarding_dismissed')->default(false);
            $table->timestampsTz();
        });

        PgConstraints::check('payflow_settings', 'payflow_settings_invoice_prefix_format', "invoice_prefix ~ '^[A-Z0-9-]{1,12}$'");
        PgConstraints::check('payflow_settings', 'payflow_settings_payout_prefix_format', "payout_prefix ~ '^[A-Z0-9-]{1,12}$'");
        PgConstraints::check('payflow_settings', 'payflow_settings_send_window', 'send_window_end > send_window_start');
        PgConstraints::in('payflow_settings', 'late_fee_type', LateFeeType::values());
        PgConstraints::between('payflow_settings', 'default_payment_terms_days', 0, 180);
        PgConstraints::between('payflow_settings', 'first_contact_max_per_hour', 1, 500);
        PgConstraints::between('payflow_settings', 'late_fee_rate_bp', 0, 10000);
        PgConstraints::between('payflow_settings', 'late_fee_grace_days', 0, 90);
        PgConstraints::between('payflow_settings', 'late_fee_cap_bp', 0, 10000);
        PgConstraints::between('payflow_settings', 'digest_hour', 0, 23);
        PgConstraints::between('payflow_settings', 'cooling_off_hours_medium', 0, 168);
        PgConstraints::between('payflow_settings', 'cooling_off_hours_high', 0, 336);
        PgConstraints::between('payflow_settings', 'escalate_pending_after_hours', 1, 168);
        PgConstraints::between('payflow_settings', 'guard_trust_delay_hours', 0, 168);
        PgConstraints::between('payflow_settings', 'solo_release_delay_hours', 0, 168);
    }

    public function down(): void
    {
        Schema::dropIfExists('payflow_settings');
    }
};
