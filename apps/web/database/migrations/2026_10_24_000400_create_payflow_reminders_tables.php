<?php

declare(strict_types=1);

use App\Domain\Collections\Enums\ReminderKind;
use App\Domain\Collections\Enums\ReminderSkipReason;
use App\Domain\Collections\Enums\ReminderStatus;
use App\Domain\Collections\Enums\Tone;
use App\Domain\Notifications\Enums\DigestChannel;
use App\Domain\Shared\Database\PgConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('policy_step_id')->nullable()->constrained('reminder_policy_steps')->nullOnDelete();
            $table->string('kind', 16);
            $table->smallInteger('step_offset_days')->nullable();
            $table->boolean('is_first_contact')->default(false);
            $table->string('tone', 16);
            $table->jsonb('tone_reasons')->default('[]');
            $table->string('locale', 2);
            $table->timestampTz('scheduled_for');
            $table->string('status', 16)->default(ReminderStatus::Scheduled->value);
            $table->string('skip_reason', 32)->nullable();
            $table->string('recipient_email')->nullable();
            $table->jsonb('cc_emails')->default('[]');
            $table->string('subject')->nullable();
            $table->text('body_html')->nullable();
            $table->text('body_text')->nullable();
            $table->bigInteger('late_fee_quoted_minor')->nullable();
            $table->uuid('outbox_message_id')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->smallInteger('delivery_attempts')->default(0);
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->index(['workspace_id', 'status', 'scheduled_for']);
            $table->index(['invoice_id', 'created_at']);
        });

        PgConstraints::uniqueWhere('reminders', 'reminders_invoice_step_unique', ['invoice_id', 'policy_step_id'], "kind = 'scheduled' AND policy_step_id IS NOT NULL");
        PgConstraints::indexWhere('reminders', 'reminders_first_contact_index', ['workspace_id', 'scheduled_for'], "is_first_contact AND status = 'scheduled'");
        PgConstraints::in('reminders', 'kind', ReminderKind::values());
        PgConstraints::in('reminders', 'tone', Tone::values());
        PgConstraints::in('reminders', 'status', ReminderStatus::values());
        PgConstraints::in('reminders', 'skip_reason', ReminderSkipReason::values(), nullable: true);
        PgConstraints::in('reminders', 'locale', ['en', 'ru']);

        Schema::create('member_notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->jsonb('telegram_events')->default('[]');
            $table->jsonb('email_events')->default('[]');
            $table->string('digest_channel', 16)->default(DigestChannel::Telegram->value);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->timestampsTz();
            $table->unique(['workspace_id', 'user_id']);
        });

        PgConstraints::in('member_notification_settings', 'digest_channel', DigestChannel::values());

        Schema::create('digest_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');
            $table->string('kind', 16)->default('daily');
            $table->integer('recipients')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->unique(['workspace_id', 'local_date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_runs');
        Schema::dropIfExists('member_notification_settings');
        Schema::dropIfExists('reminders');
    }
};
