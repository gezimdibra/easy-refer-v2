<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Portal refresh U4: the rail groups its nine items under two
 * quiet labels, Program (Overview, Customers, Referrals,
 * Rewards, Campaigns) and Needs you (Action Center, Review),
 * with QR Code and Settings ungrouped below. Labels are visual
 * only: same routes, same order, same badges (build plan P2).
 */
class PortalRailGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rail_groups_its_items_under_the_two_labels_with_routes_unchanged(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        // The desktop rail is the first Primary nav on the page.
        $navStart = strpos($html, 'aria-label="Primary"');
        $this->assertNotFalse($navStart, 'primary nav missing');
        $navEnd = strpos($html, '</nav>', (int) $navStart);
        $nav = substr($html, (int) $navStart, (int) $navEnd - (int) $navStart);

        foreach (['Program', 'Needs you'] as $label) {
            $this->assertStringContainsString($label, $nav, "rail group label {$label} missing");
        }

        // Group order: Program before its first item, Needs you
        // after Campaigns and before Action Center, and the
        // ungrouped pair below Review.
        $positions = [];
        foreach (['Program', 'Overview', 'Campaigns', 'Needs you', 'Action Center', 'Review', 'QR Code', 'Settings'] as $text) {
            $positions[$text] = strpos($nav, $text);
            $this->assertNotFalse($positions[$text], "{$text} missing from the rail");
        }
        $ordered = array_values($positions);
        $sorted = $ordered;
        sort($sorted);
        $this->assertSame($sorted, $ordered, 'rail items are out of their grouped order');

        // Href identity: the logo link plus the nine items, in
        // the pre-grouping order, nothing added or dropped. The
        // account footer's Help mailto closes the partial, as
        // before grouping.
        preg_match_all('/href="([^"]+)"/', $nav, $matches);
        $expected = array_map(
            fn (string $route) => route($route),
            ['dashboard', 'dashboard', 'customers.index', 'referrals.index', 'rewards.index', 'campaigns.index', 'actions.index', 'reviews', 'owner.qr', 'settings'],
        );
        $expected[] = 'mailto:support@easy-refer.com?subject=Easy%20Refer%20help';
        $this->assertSame($expected, $matches[1], 'rail hrefs changed under grouping');
    }

    public function test_the_badge_markup_survives_grouping(): void
    {
        $source = (string) file_get_contents(resource_path('views/partials/app-rail.blade.php'));

        $this->assertStringContainsString('data-action-center-badge', $source, 'action center badge dropped');
        $this->assertStringContainsString('$reviewQueueCount', $source, 'review badge dropped');
    }
}
