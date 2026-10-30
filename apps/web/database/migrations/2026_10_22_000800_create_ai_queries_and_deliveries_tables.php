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
        Schema::create('ai_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16);
            $table->text('question');
            $table->text('answer')->nullable();
            $table->boolean('refused')->default(false);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->jsonb('citations')->default('[]');
            $table->jsonb('tools_used')->default('[]');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 160)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'refused']);
            $table->index(['workspace_id', 'channel']);
        });

        DB::statement('CREATE UNIQUE INDEX ai_queries_request_id_unique ON ai_queries (request_id) WHERE request_id IS NOT NULL');

        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_type', 64);
            $table->string('reference_id', 64);
            $table->string('channel', 16);
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('ai_queries');
    }
};
