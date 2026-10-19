<?php

namespace Tests\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;

/**
 * Renders a row lock as a trailing SQL comment.
 *
 * The SQLite grammar compiles a row lock to an empty string, so a lock is
 * invisible in the executed statement; MySQL renders the same lock as
 * `for update`. This grammar keeps the statement valid for SQLite (a comment
 * is ignored by the engine) while making the lock visible to a query
 * listener, so a test can assert *which* statement carried a lock and in
 * which order the locks were taken.
 */
class LockRenderingSQLiteGrammar extends SQLiteGrammar
{
    protected function compileLock(Builder $query, $value): string
    {
        return '/* for update */';
    }
}
