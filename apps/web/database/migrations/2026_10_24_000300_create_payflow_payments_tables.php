<?php

declare(strict_types=1);

use App\Domain\Payments\Enums\LinkDeactivationReason;
use App\Domain\Payments\Enums\PaymentLinkKind;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Shared\Database\PgConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table): void {
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->cascadeOnDelete();
            $table->string('kind', 12)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->string('deactivation_reason', 24)->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->index(['invoice_id', 'created_at']);
        });

        PgConstraints::uniqueWhere('payment_links', 'payment_links_invoice_active_unique', ['invoice_id'], 'active AND invoice_id IS NOT NULL');
        PgConstraints::in('payment_links', 'kind', PaymentLinkKind::values(), nullable: true);
        PgConstraints::in('payment_links', 'deactivation_reason', LinkDeactivationReason::values(), nullable: true);

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('client_id')->constrained('clients');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method', 16);
            $table->string('status', 20);
            $table->bigInteger('refunded_minor')->default(0);
            $table->string('provider', 16);
            $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts')->nullOnDelete();
            $table->string('provider_payment_id')->nullable();
            $table->string('provider_checkout_session_id')->nullable();
            $table->foreignId('payment_link_id')->nullable()->constrained('payment_links')->nullOnDelete();
            $table->timestampTz('paid_at');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users');
            $table->string('reverse_reason', 500)->nullable();
            $table->timestampsTz();
            $table->index(['workspace_id', 'paid_at']);
            $table->index('invoice_id');
        });

        PgConstraints::uniqueWhere('payments', 'payments_provider_payment_unique', ['provider', 'provider_payment_id'], 'provider_payment_id IS NOT NULL');
        PgConstraints::check('payments', 'payments_amount_positive', 'amount_minor > 0');
        PgConstraints::check('payments', 'payments_refunded_range', 'refunded_minor >= 0 AND refunded_minor <= amount_minor');
        PgConstraints::in('payments', 'method', PaymentMethod::values());
        PgConstraints::in('payments', 'status', PaymentStatus::values());
        PgConstraints::in('payments', 'provider', PaymentProvider::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('payment_links', function (Blueprint $table): void {
            $table->dropIndex('payment_links_invoice_active_unique');
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn(['kind', 'active', 'expires_at', 'deactivated_at', 'deactivation_reason', 'last_synced_at']);
        });
    }
};
