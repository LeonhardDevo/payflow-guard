<?php

declare(strict_types=1);

namespace App\Domain\Shared\Database;

use Illuminate\Support\Facades\DB;

final class PgConstraints
{
    /** @param  list<string>  $values */
    public static function in(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(', ', array_map(fn (string $value): string => "'".str_replace("'", "''", $value)."'", $values));
        $condition = $nullable ? "{$column} IS NULL OR {$column} IN ({$list})" : "{$column} IN ({$list})";

        self::check($table, "{$table}_{$column}_in", $condition);
    }

    public static function between(string $table, string $column, int $min, int $max): void
    {
        self::check($table, "{$table}_{$column}_range", "{$column} IS NULL OR ({$column} >= {$min} AND {$column} <= {$max})");
    }

    public static function check(string $table, string $name, string $condition): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$condition})");
    }

    public static function dropCheck(string $table, string $name): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
    }

    /** @param  list<string>  $columns */
    public static function uniqueWhere(string $table, string $name, array $columns, string $where): void
    {
        DB::statement(sprintf('CREATE UNIQUE INDEX %s ON %s (%s) WHERE %s', $name, $table, implode(', ', $columns), $where));
    }

    /** @param  list<string>  $columns */
    public static function indexWhere(string $table, string $name, array $columns, string $where): void
    {
        DB::statement(sprintf('CREATE INDEX %s ON %s (%s) WHERE %s', $name, $table, implode(', ', $columns), $where));
    }

    public static function trigramIndex(string $table, string $column): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement("CREATE INDEX {$table}_{$column}_trgm ON {$table} USING gin ({$column} gin_trgm_ops)");
    }
}
