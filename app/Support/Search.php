<?php

namespace App\Support;

/**
 * Literal user search for LIKE queries (test-matrix XT-46:
 * search matches literally). Wildcards in the input are escaped
 * and every query using the pattern states the escape character
 * explicitly, because SQLite (tests) has no default LIKE escape
 * while MariaDB (production) defaults to backslash; the explicit
 * ESCAPE clause makes both engines agree.
 *
 * The escape character is '!', never a backslash (round-2 audit
 * F01): the ESCAPE literal sits in the SQL text, and with
 * MariaDB's default string parsing a backslash there escapes
 * the closing quote, leaving the literal unterminated and the
 * query unparseable (the branded HTTP 500 on every non-empty
 * customers search on staging). SQLite parses the same text
 * as a plain backslash, so the suite never saw it. '!' is inert
 * in both engines' string literals and special in a LIKE
 * pattern only as the declared escape character itself.
 */
final class Search
{
    public static function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }

    /**
     * A "contains" pattern for a whereRaw LIKE with ESCAPE '!'.
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
        return $column." like ? escape '!'";
    }
}
