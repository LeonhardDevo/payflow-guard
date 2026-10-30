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
        Schema::create('legal_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('document', 64);
            $table->string('version', 32);
            $table->string('locale', 5);
            $table->timestampTz('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->index(['user_id', 'document', 'accepted_at']);
            $table->index(['workspace_id', 'document']);
        });

        Schema::create('data_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 16);
            $table->string('status', 16)->default('pending');
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('downloaded_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('deletion_requests', function (Blueprint $table): void {
            $table->id();

            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('scheduled_for');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement(
            'CREATE INDEX deletion_requests_due_idx ON deletion_requests (scheduled_for) WHERE cancelled_at IS NULL AND completed_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX deletion_requests_one_pending ON deletion_requests (subject_type, subject_id) WHERE cancelled_at IS NULL AND completed_at IS NULL'
        );

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('ai_external_allowed')->default(false);
            $table->jsonb('retention')->default('{}');
        });

        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 254);

            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('reason', 32)->default('opt_out');
            $table->string('source', 32)->default('link');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement(
            'CREATE UNIQUE INDEX email_suppressions_unique ON email_suppressions (lower(email), COALESCE(workspace_id, 0))'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                IF coalesce(current_setting('app.audit_maintenance', true), 'off') = 'on' THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
                        OR NEW.action IS DISTINCT FROM OLD.action
                        OR NEW.subject_type IS DISTINCT FROM OLD.subject_type
                        OR NEW.subject_id IS DISTINCT FROM OLD.subject_id
                        OR NEW.changes IS DISTINCT FROM OLD.changes
                        OR NEW.request_id IS DISTINCT FROM OLD.request_id
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'audit_logs maintenance may only pseudonymize personal fields';
                    END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'audit_logs is append-only (% rejected)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only (% rejected)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Schema::dropIfExists('email_suppressions');

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn(['ai_external_allowed', 'retention']);
        });

        Schema::dropIfExists('deletion_requests');
        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('legal_acceptances');
    }
};
