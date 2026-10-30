<?php

namespace Tests\Feature\Owner;

use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Referral;
use App\Services\Billing\SettlementService;
use App\Services\Billing\VisitValuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RS2-3 (Value Train, Amendment 44; wording Amendment 57,
 * owner ruling 2026-10-29): the optional "How much does a
 * typical customer spend in one visit?" panel on the onboarding Finish screen,
 * writing the existing nullable businesses.average_ticket_cents
 * through the Settings -> Value discipline (same bounds, same
 * messages, same audit action). Skip = pressing a door; nothing
 * is forced. Test IDs T2-1..T2-8 refer to the value-train
 * design pass, Part 2, Section 2.6.
 */
class OnboardingValuePanelTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL_HEADING = 'How much does a typical customer spend in one visit?';

    private function finish(Business $business): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(World::owner($business))->get(route('onboarding.finish'));
    }

    private function saveValue(Business $business, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(World::owner($business))->post(route('onboarding.value'), $data);
    }

    // T2-1: unset -> the panel renders beside the doors; doing
    // nothing (the skip) leaves NULL stored and the Overview
    // estimate at $0.00, exactly today's behavior.
    public function test_unset_renders_panel_and_skip_leaves_null(): void
    {
        $business = World::business(['average_ticket_cents' => null]);

        $page = $this->finish($business);
        $page->assertOk();
        $page->assertSee(self::PANEL_HEADING);
        $page->assertSee('About what a customer spends on one visit at your store. Your Overview uses it to estimate the sales your visits brought in.');
        $page->assertSee('Typical spend per visit ($)', false);
        $page->assertSee('Your best guess is fine. You can change it any time in Settings, Value, and changing it never changes what you are charged.');
        $page->assertSee('Go to your dashboard');

        // Skip is pressing a door without saving.
        $this->actingAs(World::owner($business))->post(route('onboarding.complete'), ['door' => 'dashboard'])
            ->assertRedirect(route('dashboard'));
        $this->assertNull($business->fresh()->average_ticket_cents);
    }

    // T2-2: a ticket already carried in (> 0) -> NO panel.
    public function test_panel_absent_when_ticket_already_set(): void
    {
        $business = World::business(['average_ticket_cents' => 5000]);

        $this->finish($business)->assertDontSee(self::PANEL_HEADING);
    }

    // T2-3: set $35 with 12 visits counted -> stored 3500, the
    // audit row lands, the panel leaves the finish screen, and
    // the Overview estimate lines read the owner's own number.
    public function test_setting_the_value_flows_to_the_overview_estimate(): void
    {
        $business = World::business(['average_ticket_cents' => null, 'free_visits_remaining' => 0]);
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550988', 'Reg', 'Ular'));

        for ($i = 0; $i < 12; $i++) {
            $friend = World::customer('+1212555'.str_pad((string) (9200 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend, [
                'state' => Referral::STATE_REDEEMED,
                'redeemed_at' => now(),
            ]);
            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }

        $this->saveValue($business, ['average_ticket' => '35'])
            ->assertRedirect(route('onboarding.finish'))
            ->assertSessionHas('status', 'Value saved.');

        $this->assertSame(3500, $business->fresh()->average_ticket_cents);
        $this->assertTrue(AuditLog::query()
            ->where('action', 'business.average_ticket_updated')
            ->where('business_id', $business->id)
            ->exists());

        $this->finish($business)->assertDontSee(self::PANEL_HEADING);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSee('About $420.00 in estimated sales, based on your typical spend per visit (estimate)')
            ->assertSee('Estimates use your typical spend per visit of $35.00');
    }

    // T2-4: editing later in Settings -> Value (the existing
    // twin) moves the Overview immediately; the panel never
    // reappears.
    public function test_settings_edit_after_onboarding_save(): void
    {
        $business = World::business(['average_ticket_cents' => null]);
        $owner = World::owner($business);

        $this->saveValue($business, ['average_ticket' => '35']);
        $this->assertSame(3500, $business->fresh()->average_ticket_cents);

        $this->actingAs($owner)->post(route('settings.value.update'), ['average_ticket' => '42.50'])
            ->assertSessionHas('status', 'Value saved.');
        $this->assertSame(4250, $business->fresh()->average_ticket_cents);

        $this->finish($business)->assertDontSee(self::PANEL_HEADING);
    }

    // T2-5: input discipline, mirroring Settings exactly.
    public function test_input_bounds_and_blank_noop(): void
    {
        $business = World::business(['average_ticket_cents' => null]);

        // Blank submit = skip: no error, NULL preserved.
        $this->saveValue($business, ['average_ticket' => ''])
            ->assertRedirect(route('onboarding.finish'))
            ->assertSessionHasNoErrors();
        $this->assertNull($business->fresh()->average_ticket_cents);

        // "0" saves 0, and because the panel condition is
        // (int) === 0, a later finish visit re-offers it: the
        // designed behavior, pinned, not accidental.
        $this->saveValue($business, ['average_ticket' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(0, $business->fresh()->average_ticket_cents);
        $this->finish($business)->assertSee(self::PANEL_HEADING);

        $this->saveValue($business, ['average_ticket' => '-5'])
            ->assertSessionHasErrors(['average_ticket' => 'Enter an amount of 0 or more.']);
        $this->assertSame(0, $business->fresh()->average_ticket_cents);

        $this->saveValue($business, ['average_ticket' => 'abc'])
            ->assertSessionHasErrors(['average_ticket' => 'Enter a dollar amount, like 45 or 45.50.']);

        $this->saveValue($business, ['average_ticket' => '100000.01'])
            ->assertSessionHasErrors(['average_ticket' => 'Enter an amount under $100,000.']);

        $this->saveValue($business, ['average_ticket' => '100000'])->assertSessionHasNoErrors();
        $this->assertSame(10000000, $business->fresh()->average_ticket_cents);

        $this->saveValue($business, ['average_ticket' => '45.50'])->assertSessionHasNoErrors();
        $this->assertSame(4550, $business->fresh()->average_ticket_cents);
    }

    // T2-6: branch parity — the same panel in all three finish
    // branches (live / rewardsMissing / card).
    public function test_panel_renders_identically_in_all_three_finish_branches(): void
    {
        $live = World::business(['average_ticket_cents' => null]);
        $rewardsMissing = World::business(['average_ticket_cents' => null, 'friend_reward_text' => null, 'referrer_reward_text' => null]);
        $cardMissing = World::business(['average_ticket_cents' => null, 'billing_status' => 'none']);

        foreach ([$live, $rewardsMissing, $cardMissing] as $business) {
            $page = $this->finish($business);
            $page->assertOk();
            $page->assertSee(self::PANEL_HEADING);
            $page->assertSee('Typical spend per visit ($)', false);
            $page->assertSee(route('onboarding.value'), false);
        }
    }

    // T2-7: money isolation — the ticket moves NO billing
    // output: accrued preview, month fees, and settled coverage
    // are identical before and after setting it.
    public function test_setting_the_value_changes_no_billing_output(): void
    {
        $business = World::business(['average_ticket_cents' => null, 'free_visits_remaining' => 0]);
        [$membership] = World::membership($business, World::customer('+12125550988', 'Reg', 'Ular'));

        for ($i = 0; $i < 3; $i++) {
            $friend = World::customer('+1212555'.str_pad((string) (9300 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend, [
                'state' => Referral::STATE_REDEEMED,
                'redeemed_at' => now(),
            ]);
            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }

        $before = app(SettlementService::class)->accruedPreview($business->fresh());
        $monthBefore = (new VisitValuation)->forMonth($business->fresh(), now());

        $this->saveValue($business, ['average_ticket' => '35']);

        $after = app(SettlementService::class)->accruedPreview($business->fresh());
        $monthAfter = (new VisitValuation)->forMonth($business->fresh(), now());

        $this->assertSame($before, $after);
        $this->assertSame($monthBefore['fees_cents'], $monthAfter['fees_cents']);
        $this->assertSame(600, $monthAfter['fees_cents']);
        // Only the estimate leg moved: 3 visits x $35.
        $this->assertSame(10500, $monthAfter['estimated_sales_cents']);
    }

    // T2-8: publication absence, both directions. (a) The
    // rendered figure appears on NO customer-facing surface for
    // the same business. (b) A census pin: average_ticket is
    // read only by the known reader/writer files, so a future
    // surface that starts rendering it fails here, not in review.
    public function test_the_figure_is_never_published(): void
    {
        $business = World::business(['average_ticket_cents' => 1234500]);
        [$membership, $shareToken] = World::membership($business, World::customer('+12125550988', 'Reg', 'Ular'));
        $codes = \App\Models\QrCode::ensurePlacements($business);
        $slug = $business->ensureJoinSlug();

        // The join slug redirects to the counter code's landing;
        // the landing itself is the customer surface that must
        // never carry the figure.
        $this->get(route('join.show', ['slug' => $slug]))
            ->assertRedirect(route('refer.landing', ['token' => $codes['counter']->plainToken()]));
        $this->get(route('refer.landing', ['token' => $codes['counter']->plainToken()]))
            ->assertOk()
            ->assertDontSee('$12,345')
            ->assertDontSee('12345');

        $allowed = [
            'app/Http/Controllers/Auth/RegisteredUserController.php',
            'app/Http/Controllers/Owner/OnboardingController.php',
            'app/Http/Controllers/Owner/SettingsController.php',
            'app/Mail/DunningRecoveredMail.php',
            'app/Models/Business.php',
            'app/Services/Billing/VisitValuation.php',
            'app/Support/AuditActionLabel.php',
            'resources/views/owner/onboarding/partials/value-panel.blade.php',
            'resources/views/owner/settings/value.blade.php',
            'resources/views/partials/dashboard-value.blade.php',
        ];

        $hits = [];
        $roots = [base_path('app'), base_path('resources/views')];
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if (str_contains($contents, 'average_ticket')) {
                    $hits[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        sort($hits);
        sort($allowed);
        $this->assertSame($allowed, $hits, 'average_ticket readers/writers drifted from the RS2-3 census');
    }
}
