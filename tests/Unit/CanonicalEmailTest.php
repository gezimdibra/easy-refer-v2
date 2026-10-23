<?php

namespace Tests\Unit;

use App\Support\CanonicalEmail;
use PHPUnit\Framework\TestCase;

/**
 * EC-18 groundwork: matching is case-insensitive and ignores
 * plus-addressing tags.
 */
class CanonicalEmailTest extends TestCase
{
    public function test_case_and_plus_tags_collapse(): void
    {
        $this->assertSame('owner@example.com', CanonicalEmail::of('Owner+Promo@Example.COM'));
        $this->assertSame('owner@example.com', CanonicalEmail::of('owner@example.com'));
        $this->assertSame('owner@example.com', CanonicalEmail::of('  OWNER+anything@example.com  '));
    }

    public function test_a_string_without_an_at_sign_is_returned_lowercased(): void
    {
        $this->assertSame('not-an-email', CanonicalEmail::of('  Not-An-Email '));
    }

    public function test_distinct_mailboxes_stay_distinct(): void
    {
        $this->assertNotSame(
            CanonicalEmail::of('owner@example.com'),
            CanonicalEmail::of('other@example.com')
        );
        $this->assertNotSame(
            CanonicalEmail::of('owner@example.com'),
            CanonicalEmail::of('owner@other.example.com')
        );
    }
}
