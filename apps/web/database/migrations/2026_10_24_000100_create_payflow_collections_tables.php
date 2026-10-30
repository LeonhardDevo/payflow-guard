<?php

declare(strict_types=1);

use App\Domain\Collections\Enums\StepTone;
use App\Domain\Collections\Enums\TemplateKey;
use App\Domain\Shared\Database\PgConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->smallInteger('max_reminders')->default(8);
            $table->smallInteger('min_interval_days')->default(3);
            $table->smallInteger('escalate_after_days')->default(45);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index('workspace_id');
        });

        PgConstraints::between('reminder_policies', 'max_reminders', 1, 20);
        PgConstraints::between('reminder_policies', 'min_interval_days', 1, 30);
        PgConstraints::between('reminder_policies', 'escalate_after_days', 1, 365);
        PgConstraints::uniqueWhere('reminder_policies', 'reminder_policies_default_unique', ['workspace_id'], 'is_default AND deleted_at IS NULL');

        Schema::create('reminder_policy_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('reminder_policies')->cascadeOnDelete();
            $table->smallInteger('position');
            $table->smallInteger('offset_days');
            $table->string('tone', 16)->default(StepTone::Auto->value);
            $table->boolean('attach_pdf')->default(true);
            $table->boolean('include_payment_link')->default(true);
            $table->boolean('include_late_fee_notice')->default(false);
            $table->boolean('notify_owner')->default(false);
            $table->timestampsTz();
            $table->unique(['policy_id', 'position']);
            $table->unique(['policy_id', 'offset_days']);
            $table->index('workspace_id');
        });

        PgConstraints::between('reminder_policy_steps', 'offset_days', -30, 180);
        PgConstraints::in('reminder_policy_steps', 'tone', StepTone::values());

        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('key', 32);
            $table->string('locale', 2);
            $table->string('subject', 200);
            $table->text('body_markdown');
            $table->boolean('is_customized')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->unique(['workspace_id', 'key', 'locale']);
        });

        PgConstraints::in('message_templates', 'key', TemplateKey::values());
        PgConstraints::in('message_templates', 'locale', ['en', 'ru']);
        PgConstraints::check('message_templates', 'message_templates_body_length', 'char_length(body_markdown) <= 5000');
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('reminder_policy_steps');
        Schema::dropIfExists('reminder_policies');
    }
};
