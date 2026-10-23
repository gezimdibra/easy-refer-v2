<?php

namespace App\Support;

/**
 * Literal user search for LIKE queries (test-matrix XT-46:
 * search matches literally). Wildcards in the input are escaped
 * and every query using the pattern states the escape character
 * explicitly, because SQLite (tests) has no default LIKE escape
 * while MariaDB (production) defaults to backslash; the explicit
 * ESCAPE clause makes both engines agree.
 */
final class Search
{
    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * A "contains" pattern for a whereRaw LIKE with ESCAPE '\'.
     */
    public static function contains(string $term): string
    {
        return '%'.self::escapeLike($term).'%';
    }

    /**
     * The SQL fragment for one literal-contains column match, for
     * use inside whereRaw with the pattern as the single binding.
     */
    public static function likeClause(string $column): string
    {
        return $column." like ? escape '\\'";
    }
}
