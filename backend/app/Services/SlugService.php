<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SlugService
{
    /**
     * Generate a unique slug from a given value.
     *
     * The value is slugified via Str::slug(), then checked for uniqueness
     * in the specified table/column. If a collision is found, a numeric
     * suffix is appended (e.g. "my-slug-1").
     *
     * @param string      $value   Raw value to slugify.
     * @param string      $table   Database table to check for collisions.
     * @param string      $column  Column name (default: 'slug').
     * @param string|null $ignoreId Optional model ID to exclude (for updates).
     * @return string
     */
    public function makeUnique(string $value, string $table, string $column = 'slug', ?string $ignoreId = null): string
    {
        $slug = Str::slug($value);

        $query = DB::table($table)->where($column, 'LIKE', "{$slug}%");
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }
        $count = $query->count();

        return $count > 0 ? "{$slug}-{$count}" : $slug;
    }
}
