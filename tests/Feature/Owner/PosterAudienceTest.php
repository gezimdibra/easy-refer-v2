<?php

namespace Tests\Feature\Owner;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Audience-address check for the printed posters (owner catch,
 * 2026-10-24; the standing audience-address rule in MEMORY.md
 * and knowledge/broadcasts.md).
 *
 * The printed poster (window and counter variants) is read ONLY
 * by customers standing in the store, so every line on it must
 * address the customer directly, in the second person. The
 * shipped templates told customers "Customers scan this code to
 * join and start referring." and carried the owner-benefit band
 * line "Free for the business to run.": third-person owner-talk
 * on a customer-only surface. The owner caught it on a generated
 * poster; per the owner-correction doctrine this test is the
 * named check that closes the class. It renders both print
 * variants and scans the visible text for owner-addressed
 * phrasing, and requires the second-person scan instruction and
 * the brand credit to be present.
 */
class PosterAudienceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Phrasing that talks ABOUT customers to the owner, or sells
     * an owner benefit. None of it may appear in the visible
     * text of a printed poster (matched case-insensitively).
     *
     * @var list<string>
     */
    private array $ownerTalk = [
        'Customers scan',
        'customers should',
        'for the business',
    ];

    public function test_printed_posters_address_the_customer_not_the_owner(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        foreach (['counter', 'window'] as $variant) {
            $response = $this->actingAs($owner)->get(route('owner.qr.poster', $variant));
            $response->assertOk();

            $text = html_entity_decode(strip_tags($response->getContent()));
            $text = (string) preg_replace('/\s+/', ' ', $text);

            foreach ($this->ownerTalk as $phrase) {
                $this->assertSame(
                    0,
                    preg_match('/'.preg_quote($phrase, '/').'/i', $text),
                    "Poster variant [{$variant}] contains owner-addressed phrasing \"{$phrase}\"; "
                    .'a printed poster is read by customers only and must talk to them.'
                );
            }

            // The scan instruction speaks to the person holding
            // the phone, in the second person.
            $this->assertStringContainsString(
                'Scan this code to join and start referring.',
                $text,
                "Poster variant [{$variant}] lost its second-person scan instruction."
            );

            // The reward headline (the customer-addressed line
            // composed by Business::friendRewardHeadline()) stays.
            $this->assertStringContainsString(
                'Get $10 off your first visit',
                $text,
                "Poster variant [{$variant}] lost its reward headline."
            );

            $this->assertStringContainsString(
                'Powered by Easy Refer.',
                $text,
                "Poster variant [{$variant}] lost its band credit."
            );
        }
    }
}
