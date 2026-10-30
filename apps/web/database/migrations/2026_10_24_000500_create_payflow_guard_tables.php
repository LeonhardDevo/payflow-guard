<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Guard\Enums\AlertSeverity;
use App\Domain\Guard\Enums\AlertStatus;
use App\Domain\Guard\Enums\AlertSubjectType;
use App\Domain\Guard\Enums\AlertType;
use App\Domain\Guard\Enums\ApprovalChannel;
use App\Domain\Guard\Enums\ApprovalDecision;
use App\Domain\Guard\Enums\BankAccountStatus;
use App\Domain\Guard\Enums\BankChangeSource;
use App\Domain\Guard\Enums\BankScheme;
use App\Domain\Guard\Enums\ConfirmationMode;
use App\Domain\Guard\Enums\PayoutHoldReason;
use App\Domain\Guard\Enums\PayoutStatus;
use App\Domain\Guard\Enums\ReceivingDetailsStatus;
use App\Domain\Guard\Enums\StepUpMethod;
use App\Domain\Guard\Enums\TrustedConfirmationStatus;
use App\Domain\Guard\Enums\TrustedContactStatus;
use App\Domain\Guard\Enums\TrustedSubjectType;
use App\Domain\Guard\Enums\VendorCategory;
use App\Domain\Guard\Enums\VendorStatus;
use App\Domain\Guard\Enums\VerificationMethod;
use App\Domain\Guard\Enums\VerificationMode;
use App\Domain\Shared\Database\PgConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email');
            $table->string('locale', 2)->default('en');
            $table->string('status', 24)->default(TrustedContactStatus::PendingVerification->value);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('active_from')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        PgConstraints::uniqueWhere('trusted_contacts', 'trusted_contacts_live_unique', ['workspace_id'], "status <> 'revoked'");
        PgConstraints::in('trusted_contacts', 'status', TrustedContactStatus::values());
        PgConstraints::in('trusted_contacts', 'locale', ['en', 'ru']);

        Schema::create('trusted_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('trusted_contacts');
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->char('token_hash', 64)->unique();
            $table->jsonb('snapshot')->nullable();
            $table->string('status', 16)->default(TrustedConfirmationStatus::Pending->value);
            $table->timestampTz('expires_at');
            $table->timestampTz('decided_at')->nullable();
            $table->string('comment', 500)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->smallInteger('resend_count')->default(0);
            $table->timestampTz('reminded_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('created_at')->nullable();
            $table->index('workspace_id');
        });

        PgConstraints::uniqueWhere('trusted_confirmations', 'trusted_confirmations_pending_unique', ['subject_type', 'subject_id'], "status = 'pending'");
        PgConstraints::in('trusted_confirmations', 'subject_type', TrustedSubjectType::values());
        PgConstraints::in('trusted_confirmations', 'status', TrustedConfirmationStatus::values());

        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('legal_name', 200)->nullable();
            $table->string('category', 16)->default(VendorCategory::Contractor->value);
            $table->string('email')->nullable();
            $table->string('email_domain')->storedAs("lower(split_part(email, '@', 2))");
            $table->string('phone', 40)->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->timestampTz('phone_changed_at')->nullable();
            $table->char('country', 2)->nullable();
            $table->char('default_currency', 3);
            $table->text('tax_id')->nullable();
            $table->string('status', 16)->default(VendorStatus::Active->value);
            $table->string('hold_reason')->nullable();
            $table->unsignedBigInteger('current_bank_account_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('anonymised_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('workspace_id');
        });

        DB::statement('CREATE UNIQUE INDEX vendors_workspace_name_unique ON vendors (workspace_id, lower(name)) WHERE deleted_at IS NULL');
        PgConstraints::trigramIndex('vendors', 'name');
        PgConstraints::in('vendors', 'category', VendorCategory::values());
        PgConstraints::in('vendors', 'status', VendorStatus::values());

        Schema::create('vendor_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->integer('version');
            $table->string('scheme', 8);
            $table->text('account_holder_name');
            $table->text('iban')->nullable();
            $table->text('account_number')->nullable();
            $table->text('routing_number')->nullable();
            $table->text('swift_bic')->nullable();
            $table->string('bank_name', 200)->nullable();
            $table->char('bank_country', 2);
            $table->char('currency', 3);
            $table->char('last4', 4);
            $table->char('fingerprint', 64);
            $table->string('status', 24);
            $table->string('source', 24);
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->timestampTz('requested_at');
            $table->text('requester_contact')->nullable();
            $table->string('requester_email_domain')->nullable();
            $table->text('change_request_text')->nullable();
            $table->smallInteger('risk_score');
            $table->string('risk_band', 8);
            $table->jsonb('risk_signals')->default('[]');
            $table->string('verification_method', 32)->nullable();
            $table->jsonb('verification_checklist')->nullable();
            $table->string('verification_mode', 16)->default(VerificationMode::Standard->value);
            $table->foreignId('trusted_confirmation_id')->nullable()->constrained('trusted_confirmations');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestampTz('cooling_off_until')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['vendor_id', 'version']);
            $table->index(['workspace_id', 'fingerprint']);
        });

        PgConstraints::uniqueWhere('vendor_bank_accounts', 'vendor_bank_accounts_pending_unique', ['vendor_id'], "status = 'pending_verification'");
        PgConstraints::uniqueWhere('vendor_bank_accounts', 'vendor_bank_accounts_verified_unique', ['vendor_id'], "status = 'verified'");
        PgConstraints::in('vendor_bank_accounts', 'scheme', BankScheme::values());
        PgConstraints::in('vendor_bank_accounts', 'status', BankAccountStatus::values());
        PgConstraints::in('vendor_bank_accounts', 'source', BankChangeSource::values());
        PgConstraints::in('vendor_bank_accounts', 'risk_band', RiskBand::values());
        PgConstraints::in('vendor_bank_accounts', 'verification_method', VerificationMethod::values(), nullable: true);
        PgConstraints::in('vendor_bank_accounts', 'verification_mode', VerificationMode::values());
        PgConstraints::between('vendor_bank_accounts', 'risk_score', 0, 100);
        PgConstraints::check(
            'vendor_bank_accounts',
            'vendor_bank_accounts_four_eyes',
            "verified_by_user_id IS NULL OR verified_by_user_id <> requested_by_user_id OR (verification_mode = 'solo' AND trusted_confirmation_id IS NOT NULL)",
        );
        PgConstraints::check(
            'vendor_bank_accounts',
            'vendor_bank_accounts_solo_confirmation',
            "verification_mode <> 'solo' OR trusted_confirmation_id IS NOT NULL OR verified_by_user_id IS NULL",
        );

        DB::statement('ALTER TABLE vendors ADD CONSTRAINT vendors_current_bank_account_id_foreign FOREIGN KEY (current_bank_account_id) REFERENCES vendor_bank_accounts (id) ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED');

        Schema::create('payout_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->foreignId('vendor_bank_account_id')->nullable()->constrained('vendor_bank_accounts');
            $table->char('bank_fingerprint', 64)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('description', 500);
            $table->string('vendor_invoice_number', 100)->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 24)->default(PayoutStatus::Draft->value);
            $table->string('hold_reason', 32)->nullable();
            $table->smallInteger('risk_score')->nullable();
            $table->string('risk_band', 8)->nullable();
            $table->jsonb('risk_signals')->nullable();
            $table->smallInteger('required_approvals')->default(1);
            $table->smallInteger('approvals_count')->default(0);
            $table->smallInteger('approval_round')->default(1);
            $table->boolean('auto_approved')->default(false);
            $table->jsonb('auto_approve_basis')->nullable();
            $table->boolean('release_requires_other_user')->default(false);
            $table->timestampTz('release_not_before')->nullable();
            $table->bigInteger('split_group_total_minor')->nullable();
            $table->boolean('telegram_allowed')->default(false);
            $table->foreignId('requested_by')->constrained('users');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users');
            $table->timestampTz('released_at')->nullable();
            $table->string('paid_reference', 140)->nullable();
            $table->date('paid_on')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->foreignId('canceled_by')->nullable()->constrained('users');
            $table->string('cancel_reason', 500)->nullable();
            $table->timestampTz('last_escalated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status']);
            $table->index(['vendor_id', 'created_at']);
            $table->index(['vendor_id', 'currency', 'submitted_at']);
        });

        PgConstraints::in('payout_requests', 'status', PayoutStatus::values());
        PgConstraints::in('payout_requests', 'hold_reason', PayoutHoldReason::values(), nullable: true);
        PgConstraints::in('payout_requests', 'risk_band', RiskBand::values(), nullable: true);
        PgConstraints::between('payout_requests', 'required_approvals', 0, 2);
        PgConstraints::check('payout_requests', 'payout_requests_amount_positive', 'amount_minor > 0');
        PgConstraints::check('payout_requests', 'payout_requests_released_approved', "status <> 'released' OR approvals_count >= required_approvals");
        PgConstraints::check('payout_requests', 'payout_requests_releaser_differs', 'NOT release_requires_other_user OR released_by IS NULL OR released_by <> requested_by');

        Schema::create('payout_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_request_id')->constrained('payout_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('decision', 16);
            $table->string('channel', 16);
            $table->foreignId('trusted_confirmation_id')->nullable()->constrained('trusted_confirmations');
            $table->string('comment', 500)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->char('bank_fingerprint', 64);
            $table->string('risk_band', 8);
            $table->smallInteger('approval_round');
            $table->string('step_up_method', 16)->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->unique(['payout_request_id', 'user_id', 'approval_round']);
            $table->index('workspace_id');
        });

        PgConstraints::uniqueWhere('payout_approvals', 'payout_approvals_trusted_unique', ['payout_request_id', 'approval_round'], "channel = 'trusted_contact'");
        PgConstraints::in('payout_approvals', 'decision', ApprovalDecision::values());
        PgConstraints::in('payout_approvals', 'channel', ApprovalChannel::values());
        PgConstraints::in('payout_approvals', 'risk_band', RiskBand::values());
        PgConstraints::in('payout_approvals', 'step_up_method', StepUpMethod::values(), nullable: true);
        PgConstraints::check('payout_approvals', 'payout_approvals_trusted_user', "(channel = 'trusted_contact') = (user_id IS NULL)");
        PgConstraints::check('payout_approvals', 'payout_approvals_trusted_confirmation', "channel <> 'trusted_contact' OR trusted_confirmation_id IS NOT NULL");

        Schema::create('guard_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('severity', 16);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('data')->default('{}');
            $table->string('status', 16)->default(AlertStatus::Open->value);
            $table->string('dedupe_key', 160);
            $table->foreignId('acknowledged_by')->nullable()->constrained('users');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestampsTz();
            $table->index(['workspace_id', 'status', 'severity']);
            $table->index(['subject_type', 'subject_id']);
        });

        PgConstraints::uniqueWhere('guard_alerts', 'guard_alerts_dedupe_unique', ['workspace_id', 'dedupe_key'], "status <> 'resolved'");
        PgConstraints::in('guard_alerts', 'type', AlertType::values());
        PgConstraints::in('guard_alerts', 'severity', AlertSeverity::values());
        PgConstraints::in('guard_alerts', 'status', AlertStatus::values());
        PgConstraints::in('guard_alerts', 'subject_type', AlertSubjectType::values());

        Schema::create('receiving_details_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->integer('version');
            $table->text('details');
            $table->char('details_hash', 64);
            $table->string('status', 24)->default(ReceivingDetailsStatus::PendingConfirmation->value);
            $table->foreignId('requested_by')->constrained('users');
            $table->timestampTz('requested_at');
            $table->string('confirmation_mode', 24)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->foreignId('trusted_confirmation_id')->nullable()->constrained('trusted_confirmations');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('effective_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestampTz('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['workspace_id', 'version']);
        });

        PgConstraints::uniqueWhere('receiving_details_versions', 'receiving_details_pending_unique', ['workspace_id'], "status IN ('pending_confirmation', 'scheduled')");
        PgConstraints::uniqueWhere('receiving_details_versions', 'receiving_details_active_unique', ['workspace_id'], "status = 'active'");
        PgConstraints::in('receiving_details_versions', 'status', ReceivingDetailsStatus::values());
        PgConstraints::in('receiving_details_versions', 'confirmation_mode', ConfirmationMode::values(), nullable: true);
        PgConstraints::check('receiving_details_versions', 'receiving_details_confirmer_differs', 'confirmed_by IS NULL OR confirmed_by <> requested_by');
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_details_versions');
        Schema::dropIfExists('guard_alerts');
        Schema::dropIfExists('payout_approvals');
        Schema::dropIfExists('payout_requests');
        DB::statement('ALTER TABLE IF EXISTS vendors DROP CONSTRAINT IF EXISTS vendors_current_bank_account_id_foreign');
        Schema::dropIfExists('vendor_bank_accounts');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('trusted_confirmations');
        Schema::dropIfExists('trusted_contacts');
    }
};
