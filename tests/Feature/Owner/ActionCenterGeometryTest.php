<?php

namespace Tests\Feature\Owner;

use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The action-row geometry pin (Train 2 fix round, T2R-01;
 * standing check in knowledge/build-and-test.md). Train 2's
 * Action Center shipped rows of `flex flex-wrap` with the line
 * as `min-w-0 flex-1`: at phone width the wrap never triggered,
 * the line was crushed into a ~98px column (about a quarter of
 * the card), and the longer action buttons painted over the
 * text — the Train 1 F-05 class on a new surface. F-05's pin
 * covers the QR poster list only; this pin is the same
 * measurement for the Action Center, permanently:
 *
 *   at 390 AND 375, every row's line spans the card's full
 *   content width (the stacked layout: line on top, actions
 *   below), and no action element's box overlaps the line's
 *   box.
 *
 * The measurement runs in the WebKit rig
 * (tests/Support/action-row-probe.mjs) against the page the
 * test client rendered. On a machine without the rig the test
 * skips rather than fails; on this machine it is part of the
 * suite.
 */
class ActionCenterGeometryTest extends TestCase
{
    use RefreshDatabase;

    private const WIDTHS = [390, 375];

    private static ?array $probe = null;

    /**
     * A business with five Action Center rows open, including
     * the two rows whose buttons overlapped the text in the
     * review's measurement ("View campaign", "Add review
     * link"): past_due, review holds, a failed campaign
     * delivery, the missing review link, and the unfinished
     * checklist.
     */
    private function populatedBusiness()
    {
        $business = World::business([
            'square_customer_id' => 'sq_customer_1',
            'billing_status' => 'past_due',
            // google_review_url deliberately unset: the
            // review-link row (and its checklist step) is open.
        ]);

        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();

        // Close the checklist's test-referral and poster steps
        // so the remaining open step is the review link alone;
        // the row set below does not depend on which steps are
        // open, only on the count staying under the total.
        World::membership($business, World::customer($owner->phone_e164));
        ReferralEvent::create([
            'business_id' => $business->id,
            'event_type' => 'poster_downloaded',
            'actor' => 'owner',
        ]);

        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_1',
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => (int) now()->addYears(3)->format('Y'),
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
        ]);

        // A redeemed referral: the review-link row's trigger.
        $referrer = World::customer('+12125550401', 'Rae', 'Six');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550402', 'Fin', 'Seven');
        [$referral] = World::referral($membership, $friend);
        $referral->forceFill(['state' => Referral::STATE_REDEEMED])->save();

        // A held referral: the review-holds row.
        $holder = World::customer('+12125550403', 'Hal', 'Eight');
        [$holdMembership] = World::membership($business, $holder);
        $holdFriend = World::customer('+12125550404', 'Flo', 'Nine');
        [$held] = World::referral($holdMembership, $holdFriend);
        $held->forceFill(['hold' => true, 'held_at' => now()])->save();

        // A sent campaign with a failed delivery.
        $customer = World::customer('+12125550405', 'Cam', 'Ten');
        [$campaignMembership] = World::membership($business, $customer);
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'A past campaign',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id,
            'sent_at' => now(),
        ]);
        SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $campaignMembership->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'delivery_state' => SmsCampaignRecipient::STATE_FAILED,
        ]);

        return [$business->fresh(), $owner];
    }

    private function probe(): array
    {
        if (self::$probe !== null) {
            return self::$probe;
        }

        $rig = getenv('POSTER_RIG_DIR') ?: getenv('HOME').'/workspace/webkit-spike';
        if (! is_dir($rig.'/node_modules/playwright') || ! is_dir($rig.'/browsers')) {
            $this->markTestSkipped('WebKit rig not available at '.$rig.'; action-row geometry is unmeasured on this machine.');
        }
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node not on PATH; action-row geometry is unmeasured on this machine.');
        }

        [, $owner] = $this->populatedBusiness();

        $dir = sys_get_temp_dir().'/action-row-'.getmypid();
        @mkdir($dir, 0777, true);

        // The suite disables Vite (TestCase::withoutVite), so
        // the rendered page carries no stylesheet link; the
        // probe needs the real built CSS or every measurement
        // is of an unstyled page. Inject the one stylesheet the
        // live page loads, named by the build manifest (the
        // PosterPrintFitTest pattern).
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        if ($cssFile === null) {
            $this->markTestSkipped('Build manifest has no app stylesheet; action-row geometry is unmeasured.');
        }
        $cssLink = '<link rel="stylesheet" href="/build/'.$cssFile.'">';

        $html = $this->actingAs($owner)->get(route('actions.index'))->assertOk()->getContent();
        $origin = rtrim((string) config('app.url'), '/');
        $html = str_replace($origin, '', $html);
        $html = str_replace('</head>', $cssLink.'</head>', $html);
        file_put_contents($dir.'/actions.html', $html);

        $config = $dir.'/config.json';
        file_put_contents($config, json_encode([
            'pagesDir' => $dir,
            'publicDir' => public_path(),
            'rigDir' => $rig,
            'widths' => self::WIDTHS,
        ]));

        $cmd = 'POSTER_RIG_DIR='.escapeshellarg($rig)
            .' PLAYWRIGHT_BROWSERS_PATH='.escapeshellarg($rig.'/browsers')
            .' timeout 120 '.$node.' '.escapeshellarg(__DIR__.'/../../Support/action-row-probe.mjs')
            .' '.escapeshellarg($config).' 2>/dev/null';
        $json = (string) shell_exec($cmd);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $this->fail('Action-row probe produced no measurement. Command: '.$cmd);
        }

        return self::$probe = $decoded;
    }

    public function test_action_rows_stack_at_phone_widths_with_no_overlap(): void
    {
        $probe = $this->probe();

        foreach (self::WIDTHS as $width) {
            $this->assertArrayHasKey($width, $probe, "No probe measurement at {$width}px.");
            $rows = $probe[$width]['rows'];

            // Anti-vacuity: the fixture composes five rows,
            // including the two the review measured overlapping.
            // An empty or short selection is a broken pin, never
            // a pass.
            $this->assertGreaterThanOrEqual(
                5,
                count($rows),
                "Only ".count($rows)." action rows measured at {$width}px; the pin is not measuring the populated page."
            );
            $labels = collect($rows)->flatMap(fn ($row) => collect($row['actions'])->pluck('label'))->all();
            $this->assertContains('View campaign', $labels, "The failed-delivery row is missing at {$width}px.");
            $this->assertContains('Add review link', $labels, "The review-link row is missing at {$width}px.");

            foreach ($rows as $row) {
                $this->assertGreaterThanOrEqual(
                    $row['contentW'] - 1,
                    $row['lineW'],
                    "At {$width}px the line \"{$row['line']}\" spans {$row['lineW']}px of the card's {$row['contentW']}px content width; the row is not stacked."
                );
                $this->assertSame(
                    0,
                    $row['overlapCount'],
                    "At {$width}px an action overlaps the line \"{$row['line']}\": ".json_encode($row['actions'])
                );
            }
        }
    }
}
