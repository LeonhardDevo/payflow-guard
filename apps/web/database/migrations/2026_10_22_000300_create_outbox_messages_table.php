<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE outbox_messages (
                id uuid PRIMARY KEY,
                topic varchar(120) NOT NULL,
                payload jsonb NOT NULL,
                workspace_id bigint NULL,
                available_at timestamptz NOT NULL DEFAULT now(),
                dispatched_at timestamptz NULL,
                attempts integer NOT NULL DEFAULT 0,
                last_error text NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        DB::statement(
            'CREATE INDEX outbox_messages_pending_idx ON outbox_messages (available_at) WHERE dispatched_at IS NULL'
        );
        DB::statement('CREATE INDEX outbox_messages_topic_idx ON outbox_messages (topic, created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
