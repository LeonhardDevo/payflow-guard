<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('original_name');
            $table->string('mime', 160);
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk', 32);
            $table->string('path');
            $table->char('sha256', 64);
            $table->string('department', 120)->nullable();
            $table->jsonb('roles')->default('[]');
            $table->jsonb('tags')->default('[]');
            $table->string('version', 40)->nullable();
            $table->date('effective_date')->nullable();
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('pages')->nullable();
            $table->unsignedInteger('chunks')->nullable();
            $table->boolean('ocr_used')->default(false);
            $table->text('error')->nullable();
            $table->timestampTz('indexed_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'department']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
