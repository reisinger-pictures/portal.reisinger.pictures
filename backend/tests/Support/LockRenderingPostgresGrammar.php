<?php

namespace Tests\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Renders a row lock as a trailing SQL comment on PostgreSQL.
 *
 * Mirrors {@see LockRenderingSQLiteGrammar}: the marker makes the lock
 * visible to a query listener so a test can assert which statement carried
 * it and in which order. PostgreSQL's native grammar is kept so that real
 * writes in the same connection — notably Sanctum's `createToken()` INSERT,
 * which needs `returning "id"` — still compile to valid SQL.
 */
class LockRenderingPostgresGrammar extends PostgresGrammar
{
    protected function compileLock(Builder $query, $value): string
    {
        return '/* for update */';
    }
}
