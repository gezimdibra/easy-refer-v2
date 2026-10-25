<?php

namespace Tests\Unit;

use App\Support\Search;
use PHPUnit\Framework\TestCase;

/**
 * Literal search (XT-46) and the F01 portability invariant
 * (round-2 UI/UX audit): the ESCAPE literal is embedded in the
 * SQL text, so it must be a character both engines parse the
 * same way inside a string literal. A backslash is not: with
 * MariaDB's default string parsing, escape '\' is an escaped
 * quote and the literal never terminates (parse error, the
 * branded HTTP 500 on every non-empty customers search on
 * staging), while SQLite reads the same text as a plain
 * backslash and the suite stayed green. The escape character
 * is '!', inert in both engines' string literals and in LIKE
 * patterns except as the declared escape.
 */
class SearchTest extends TestCase
{
    public function test_like_clause_declares_a_portable_escape_character(): void
    {
        $clause = Search::likeClause('first_name');

        $this->assertSame("first_name like ? escape '!'", $clause);
        $this->assertStringNotContainsString('\\', $clause);
    }

    public function test_contains_escapes_wildcards_and_the_escape_character(): void
    {
        $this->assertSame('%plain%', Search::contains('plain'));
        $this->assertSame('%50!%%', Search::contains('50%'));
        $this->assertSame('%a!_b%', Search::contains('a_b'));
        $this->assertSame('%wow!!%', Search::contains('wow!'));

        // A backslash in the input is ordinary text to match
        // literally, not an escape: it passes through untouched.
        $this->assertSame('%a\\b%', Search::contains('a\\b'));
    }
}
