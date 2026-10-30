<?php

namespace Tests\Feature\Owner;

use App\Models\Business;
use App\Models\QrCode;
use App\Models\ReferralEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * QRK-07 (smart-build-queue.md §5, Train 3): the QR page's
 * placements panel. Per-placement scans and joins derived from
 * the scan events QRK-05 stamps; per-placement Print + Replace;
 * one "Replace all codes" danger action. Copy is Amendment 23
 * (REF-POSTER-03). The retired single-code copy
 * ("This is your store's only active code.") is absence-pinned.
 *
 * P3 poster mix (Amendment 27): the panel now renders as the
 * by-spot list (MIX-2): the Added date is gone, stats read
 * "{n} scans · {n} joins so far", and the FE-LBL-01 footnote
 * slot reads "Totals are all time." The derivation, the modal
 * wiring, and the danger zone are unchanged.
 */
class QrPlacementsPanelTest extends TestCase
{
    use RefreshDatabase;

    /** The panel row HTML for one placement. */
    private function row(string $html, string $placement): string
    {
        $this->assertMatchesRegularExpression(
            '/data-placement="'.preg_quote($placement, '/').'"/',
            $html,
            "The placements panel has no row for {$placement}."
        );
        preg_match('/data-placement="'.preg_quote($placement, '/').'".*?<\/li>/s', $html, $m);

        return $m[0] ?? '';
    }

    private function seedScan(Business $business, QrCode $code, $membership, ?array $metadata = null): void
    {
        ReferralEvent::record('scan', 'customer', $business, null, null, $membership,
            $metadata ?? ['qr_code_id' => $code->id, 'placement' => $code->placement]);
    }

    public function test_panel_counts_match_seeded_scan_events_per_placement(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $codes = QrCode::ensurePlacements($business);

        [$m1] = World::membership($business, World::customer('+12125550111'));
        [$m2] = World::membership($business, World::customer('+12125550222'));
        [$m3] = World::membership($business, World::customer('+12125550333'));

        // Table: three scans by two customers.
        $this->seedScan($business, $codes['table'], $m1);
        $this->seedScan($business, $codes['table'], $m1);
        $this->seedScan($business, $codes['table'], $m2);
        // Counter: one stamped scan...
        $this->seedScan($business, $codes['counter'], $m1);
        // ...plus a legacy scan with no placement metadata, which
        // predates placements and counts toward the counter code.
        ReferralEvent::record('scan', 'customer', $business, null, null, $m3, []);

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();

        $this->assertStringContainsString('Print by spot', $html);
        $this->assertStringContainsString(
            'Every spot gets its own code, so you can see which spot brings people in.',
            $html
        );

        $table = $this->row($html, 'table');
        $this->assertStringContainsString('3 scans · 2 joins so far', $table, 'Table stats should read 3 scans, 2 joins.');

        $counter = $this->row($html, 'counter');
        $this->assertStringContainsString('2 scans · 2 joins so far', $counter, 'Counter stats should be 2 scans (one stamped, one legacy), 2 joins.');

        foreach (['window', 'receipt'] as $placement) {
            $rowHtml = $this->row($html, $placement);
            // QF-3 (Amendment 29 [QF-1]): a spot at zero scans
            // and zero joins reads "No scans yet", not the
            // numeric form (pinned in full in QrFrameTest).
            $this->assertStringContainsString('No scans yet', $rowHtml, "{$placement} should show the zero state.");
            $this->assertStringNotContainsString('0 scans · 0 joins so far', $rowHtml);
        }

        // SA-06 (Amendment 41, superseding the MIX-2 removal):
        // the Added date is back on every row, business-local,
        // beside the last-scan date; pinned in full in
        // QrReceiptTrainTest.
        $this->assertStringContainsString('Added ', $html);

        // FE-LBL-01 footnote slot (Amendment 25) in its MIX form.
        $this->assertStringContainsString('Totals are all time.', $html);
        $this->assertStringNotContainsString('Scans and joins count customers who joined through that code.', $html);
    }

    public function test_panel_replace_modal_copy_and_revokes_only_that_code(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $codes = QrCode::ensurePlacements($business);
        $hashesBefore = collect($codes)->map(fn (QrCode $c) => $c->token_hash);

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();

        // [ON-SPOT-3] (Amendment 31) modal copy: one title for
        // every code, the body reading the current display
        // name (the window default here).
        $this->assertStringContainsString('Replace this code?', $html);
        $this->assertStringContainsString(
            'Printed copies of the Window poster code will stop working right away. Your other codes keep working.',
            $html
        );
        $this->assertStringContainsString('Replace code', $html);

        $this->actingAs($owner)
            ->post(route('owner.qr.placement.regenerate', ['placement' => 'window']))
            ->assertRedirect(route('owner.qr'))
            ->assertSessionHas('status', 'New code is ready for Window poster. Print a new poster for that spot.');

        $this->get(route('refer.landing', ['token' => $codes['window']->plainToken()]))->assertNotFound();

        foreach (['counter', 'table', 'receipt'] as $placement) {
            $row = $business->qrCodes()->where('placement', $placement)->where('state', 'active')->sole();
            $this->assertSame($hashesBefore[$placement], $row->token_hash, "{$placement} must be untouched.");
            $this->get(route('refer.landing', ['token' => $codes[$placement]->plainToken()]))->assertOk();
        }
    }

    public function test_replace_all_codes_from_the_danger_zone(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $codes = QrCode::ensurePlacements($business);

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();

        // REF-POSTER-03c danger copy.
        $this->assertStringContainsString('Replace all codes?', $html);
        $this->assertStringContainsString(
            'Every printed code for your store will stop working right away. Print new posters for each spot after you replace them.',
            $html
        );

        $this->actingAs($owner)
            ->post(route('owner.qr.regenerate'))
            ->assertRedirect(route('owner.qr'))
            ->assertSessionHas('status', 'All new codes are ready. Print new posters to replace the old ones.');

        foreach ($codes as $placement => $code) {
            $this->assertSame('revoked', $code->fresh()->state, "The old {$placement} code must be revoked.");
            $this->get(route('refer.landing', ['token' => $code->plainToken()]))->assertNotFound();
        }

        $this->assertSame(4, $business->qrCodes()->where('state', 'active')->count());
    }

    public function test_only_active_code_copy_is_gone_everywhere(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();

        $this->assertStringNotContainsString("This is your store's only active code", $html);
        // MOVED (Amendment 31 [ON-SPOT-3]): "Replace this
        // code?" is no longer retired copy; it is the current
        // per-placement modal title, so the absence pin that
        // stood here is superseded (the modal title is pinned
        // present in test_panel_replace_modal_copy_and_revokes_only_that_code).
        $this->assertStringNotContainsString('Your new QR code is ready.', $html);
    }

    public function test_panel_print_actions_open_each_placements_poster(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        QrCode::ensurePlacements($business);

        $html = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();

        // Counter prints the counter poster, Window the window
        // poster, Table the tent, Receipt the receipt card.
        $expected = ['counter' => 'counter', 'window' => 'window', 'table' => 'tent', 'receipt' => 'receipt'];
        foreach ($expected as $placement => $variant) {
            $rowHtml = $this->row($html, $placement);
            $this->assertStringContainsString(
                route('owner.qr.poster', ['variant' => $variant]),
                $rowHtml,
                "The {$placement} row should print the {$variant} poster."
            );
        }
    }

    public function test_each_placements_poster_prints_its_own_code(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $codes = QrCode::ensurePlacements($business);

        // The window poster's image is the WINDOW code's image,
        // not the counter code's (kit §4: a poster prints the
        // code for its placement).
        $windowPoster = $this->actingAs($owner)->get(route('owner.qr.poster', 'window'))->assertOk()->getContent();
        $this->assertStringContainsString(route('owner.qr.image.placement', ['placement' => 'window']), $windowPoster);

        $tentPoster = $this->actingAs($owner)->get(route('owner.qr.poster', 'tent'))->assertOk()->getContent();
        $this->assertStringContainsString(route('owner.qr.image.placement', ['placement' => 'table']), $tentPoster);

        // The placement image endpoint serves that placement's
        // code: its SVG differs from the counter image's.
        $windowImage = $this->actingAs($owner)->get(route('owner.qr.image.placement', ['placement' => 'window']))->assertOk();
        $counterImage = $this->actingAs($owner)->get(route('owner.qr.image'))->assertOk();
        $this->assertNotSame($counterImage->getContent(), $windowImage->getContent());
        $this->assertNotSame($codes['counter']->plainToken(), $codes['window']->plainToken());
    }
}
