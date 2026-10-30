<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\RiskBand;
use App\Domain\Imports\Enums\ImportActivationStatus;
use App\Domain\Imports\Enums\ImportKind;
use App\Domain\Imports\Enums\ImportPreset;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Receivables\Enums\ClientPauseSource;
use App\Domain\Receivables\Enums\EventActorType;
use App\Domain\Receivables\Enums\InvoiceEventType;
use App\Domain\Receivables\Enums\InvoiceItemKind;
use App\Domain\Receivables\Enums\InvoicePauseSource;
use App\Domain\Receivables\Enums\InvoiceSource;
use App\Domain\Receivables\Enums\InvoiceStatus;
use App\Domain\Receivables\Enums\LateFeeType;
use App\Domain\Receivables\Enums\LatePayerClass;
use App\Domain\Receivables\Enums\ToneCeiling;
use App\Domain\Shared\Database\PgConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('legal_name', 200)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('email')->nullable();
            $table->jsonb('cc_emails')->default('[]');
            $table->string('phone', 40)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->text('address')->nullable();
            $table->string('locale', 2)->default('en');
            $table->char('currency', 3);
            $table->smallInteger('payment_terms_days')->nullable();
            $table->foreignId('reminder_policy_id')->nullable()->constrained('reminder_policies')->nullOnDelete();
            $table->string('tone_ceiling', 16)->default(ToneCeiling::None->value);
            $table->boolean('reminders_paused')->default(false);
            $table->date('reminders_paused_until')->nullable();
            $table->string('reminders_pause_source', 24)->nullable();
            $table->string('pause_reason')->nullable();
            $table->timestampTz('opted_out_at')->nullable();
            $table->string('late_fee_type', 16)->nullable();
            $table->integer('late_fee_rate_bp')->nullable();
            $table->bigInteger('late_fee_fixed_minor')->nullable();
            $table->smallInteger('late_fee_grace_days')->nullable();
            $table->integer('late_fee_cap_bp')->nullable();
            $table->foreignId('contract_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users');
            $table->jsonb('tags')->default('[]');
            $table->text('notes')->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->integer('paid_invoices_count')->default(0);
            $table->integer('late_invoices_count')->default(0);
            $table->decimal('avg_days_late', 6, 2)->default(0);
            $table->decimal('on_time_rate', 5, 4)->nullable();
            $table->string('late_payer_class', 16)->default(LatePayerClass::New->value);
            $table->bigInteger('open_balance_minor')->default(0);
            $table->bigInteger('overdue_balance_minor')->default(0);
            $table->timestampTz('stats_computed_at')->nullable();
            $table->timestampTz('anonymised_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('workspace_id');
            $table->index(['workspace_id', 'late_payer_class']);
        });

        DB::statement('CREATE UNIQUE INDEX clients_workspace_name_unique ON clients (workspace_id, lower(name)) WHERE deleted_at IS NULL');
        PgConstraints::uniqueWhere('clients', 'clients_workspace_external_ref_unique', ['workspace_id', 'external_ref'], 'external_ref IS NOT NULL');
        PgConstraints::trigramIndex('clients', 'name');
        PgConstraints::in('clients', 'locale', ['en', 'ru']);
        PgConstraints::in('clients', 'tone_ceiling', ToneCeiling::values());
        PgConstraints::in('clients', 'reminders_pause_source', ClientPauseSource::values(), nullable: true);
        PgConstraints::in('clients', 'late_fee_type', LateFeeType::values(), nullable: true);
        PgConstraints::in('clients', 'late_payer_class', LatePayerClass::values());
        PgConstraints::between('clients', 'payment_terms_days', 0, 180);
        PgConstraints::between('clients', 'late_fee_rate_bp', 0, 10000);
        PgConstraints::between('clients', 'late_fee_grace_days', 0, 90);
        PgConstraints::between('clients', 'late_fee_cap_bp', 0, 10000);
        PgConstraints::check('clients', 'clients_currency_format', "currency ~ '^[A-Z]{3}$'");

        Schema::create('imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('preset', 16)->default(ImportPreset::Generic->value);
            $table->string('status', 24)->default(ImportStatus::Uploaded->value);
            $table->string('file_path', 500)->nullable();
            $table->string('original_name');
            $table->integer('size_bytes');
            $table->char('delimiter', 1)->default(',');
            $table->jsonb('headers')->default('[]');
            $table->jsonb('mapping')->nullable();
            $table->jsonb('options')->default('{}');
            $table->integer('rows_total')->default(0);
            $table->integer('rows_processed')->default(0);
            $table->integer('rows_imported')->default(0);
            $table->integer('rows_skipped')->default(0);
            $table->integer('rows_failed')->default(0);
            $table->jsonb('errors')->default('[]');
            $table->string('activation_status', 16)->default(ImportActivationStatus::Pending->value);
            $table->timestampTz('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['workspace_id', 'created_at']);
        });

        PgConstraints::in('imports', 'kind', ImportKind::values());
        PgConstraints::in('imports', 'preset', ImportPreset::values());
        PgConstraints::in('imports', 'status', ImportStatus::values());
        PgConstraints::in('imports', 'activation_status', ImportActivationStatus::values());
        PgConstraints::check('imports', 'imports_size_limit', 'size_bytes >= 0 AND size_bytes <= 5242880');

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients');
            $table->string('number', 40)->nullable();
            $table->string('status', 16)->default(InvoiceStatus::Draft->value);
            $table->char('currency', 3);
            $table->string('locale', 2);
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('po_number', 64)->nullable();
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('amount_paid_minor')->default(0);
            $table->bigInteger('balance_due_minor')->storedAs('total_minor - amount_paid_minor');
            $table->bigInteger('late_fee_applied_minor')->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('public_token', 48)->unique();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('first_viewed_at')->nullable();
            $table->timestampTz('last_viewed_at')->nullable();
            $table->integer('view_count')->default(0);
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestampTz('written_off_at')->nullable();
            $table->string('write_off_reason')->nullable();
            $table->timestampTz('disputed_at')->nullable();
            $table->text('dispute_reason')->nullable();
            $table->timestampTz('dispute_resolved_at')->nullable();
            $table->string('status_before_dispute', 16)->nullable();
            $table->boolean('reminders_paused')->default(false);
            $table->date('reminders_paused_until')->nullable();
            $table->string('reminders_pause_source', 24)->nullable();
            $table->timestampTz('first_contact_confirmed_at')->nullable();
            $table->foreignId('first_contact_confirmed_by')->nullable()->constrained('users');
            $table->timestampTz('client_reported_paid_at')->nullable();
            $table->string('client_reported_reference', 140)->nullable();
            $table->smallInteger('reminders_sent_count')->default(0);
            $table->timestampTz('last_reminder_at')->nullable();
            $table->smallInteger('risk_score')->nullable();
            $table->string('risk_band', 8)->nullable();
            $table->jsonb('risk_factors')->nullable();
            $table->date('expected_paid_on')->nullable();
            $table->timestampTz('risk_computed_at')->nullable();
            $table->string('source', 16)->default(InvoiceSource::Manual->value);
            $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->string('external_ref', 100)->nullable();
            $table->boolean('paid_at_estimated')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['workspace_id', 'status', 'due_date']);
            $table->index(['workspace_id', 'client_id', 'status']);
            $table->index(['workspace_id', 'issue_date']);
        });

        PgConstraints::uniqueWhere('invoices', 'invoices_workspace_number_unique', ['workspace_id', 'number'], 'number IS NOT NULL');
        PgConstraints::uniqueWhere('invoices', 'invoices_workspace_external_ref_unique', ['workspace_id', 'external_ref'], 'external_ref IS NOT NULL');
        PgConstraints::indexWhere('invoices', 'invoices_open_risk_band_index', ['workspace_id', 'risk_band'], "status IN ('open', 'partially_paid', 'disputed')");
        PgConstraints::in('invoices', 'status', InvoiceStatus::values());
        PgConstraints::in('invoices', 'status_before_dispute', InvoiceStatus::values(), nullable: true);
        PgConstraints::in('invoices', 'locale', ['en', 'ru']);
        PgConstraints::in('invoices', 'reminders_pause_source', InvoicePauseSource::values(), nullable: true);
        PgConstraints::in('invoices', 'risk_band', RiskBand::values(), nullable: true);
        PgConstraints::in('invoices', 'source', InvoiceSource::values());
        PgConstraints::between('invoices', 'risk_score', 0, 100);
        PgConstraints::check('invoices', 'invoices_due_after_issue', 'due_date >= issue_date');
        PgConstraints::check('invoices', 'invoices_amounts_non_negative', 'subtotal_minor >= 0 AND tax_minor >= 0 AND total_minor >= 0 AND amount_paid_minor >= 0');
        PgConstraints::check('invoices', 'invoices_currency_format', "currency ~ '^[A-Z]{3}$'");

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->smallInteger('position');
            $table->string('kind', 16)->default(InvoiceItemKind::Service->value);
            $table->string('description', 500);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->bigInteger('unit_price_minor');
            $table->integer('tax_rate_bp')->default(0);
            $table->bigInteger('amount_minor');
            $table->bigInteger('tax_minor');
            $table->timestampsTz();
            $table->unique(['invoice_id', 'position']);
            $table->index('workspace_id');
        });

        PgConstraints::in('invoice_items', 'kind', InvoiceItemKind::values());
        PgConstraints::check('invoice_items', 'invoice_items_quantity_positive', 'quantity > 0');
        PgConstraints::check('invoice_items', 'invoice_items_price_non_negative', 'unit_price_minor >= 0');
        PgConstraints::between('invoice_items', 'tax_rate_bp', 0, 10000);

        Schema::create('invoice_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients');
            $table->string('type', 32);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->jsonb('data')->default('{}');
            $table->timestampTz('occurred_at');
            $table->index(['invoice_id', 'occurred_at']);
            $table->index(['workspace_id', 'type', 'occurred_at']);
        });

        PgConstraints::in('invoice_events', 'type', InvoiceEventType::values());
        PgConstraints::in('invoice_events', 'actor_type', EventActorType::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_events');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('imports');
        Schema::dropIfExists('clients');
    }
};
