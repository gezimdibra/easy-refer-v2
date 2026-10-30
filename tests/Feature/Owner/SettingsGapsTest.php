<?php

namespace Tests\Feature\Owner;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Post-r26 gap pack 05 (settings gaps): SP-1/SP-2 (profile),
 * SN-1/SN-2 (notifications), SL-1/SL-2 (limits), SI-1/SI-2/SI-3
 * (settings index). Copy: Amendment 29 section 17.18, RATIFIED.
 */
class SettingsGapsTest extends TestCase
{
    use RefreshDatabase;

    private function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    // SP-1: grouped profile form, lede, where-it-appears note.

    public function test_sp1_profile_groups_lede_and_field_order(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('settings.profile'))->assertOk()->getContent();

        $this->assertStringContainsString('How your business appears to customers, and how we reach you.', $html);
        $this->assertStringContainsString('This name is on your posters and the pages customers see.', $html);

        $positions = [];
        foreach (['Your business', 'name="name"', 'name="business_type"', 'How we reach you', 'name="contact_email"', 'name="contact_phone"', 'Address', 'name="street"', 'name="city"', 'name="state"', 'name="postal_code"', 'name="country"'] as $needle) {
            $pos = strpos($html, $needle);
            $this->assertNotFalse($pos, "Missing {$needle}");
            $positions[] = $pos;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Profile groups or fields are out of order.');
    }

    // SP-2: the single-source error summary contract (the
    // form-error-summary component was deleted in the 2026-10-29
    // EMPTY ERROR SUMMARY class fix; partials/notices is the one
    // summary component app-wide).

    public function test_sp2_component_renders_anchor_links_and_focus_target(): void
    {
        $bag = new ViewErrorBag;
        $bag->put('default', new MessageBag([
            'name' => ['Enter your business name.'],
            'contact_email' => ['Enter a valid email address.'],
        ]));

        $html = view('partials.notices', ['errors' => $bag])->render();

        $this->assertStringContainsString('data-error-summary', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('2 things to fix:', $html);
        $this->assertStringContainsString('href="#name"', $html);
        $this->assertStringContainsString('Enter your business name.', $html);
        $this->assertStringContainsString('href="#contact_email"', $html);
        $this->assertStringContainsString('Enter a valid email address.', $html);
        $this->assertStringContainsString('focus()', $html);

        // Named bags merge into the same summary: a message in a
        // non-default bag is counted and listed too (the password
        // form's bag was invisible to the old default-only read).
        $bag->put('password', new MessageBag([
            'current_password' => ['The provided password is incorrect.'],
        ]));
        $html2 = view('partials.notices', ['errors' => $bag])->render();
        $this->assertStringContainsString('3 things to fix:', $html2);
        $this->assertStringContainsString('href="#current_password"', $html2);
        $this->assertStringContainsString('The provided password is incorrect.', $html2);
    }

    public function test_sp2_component_renders_nothing_without_errors(): void
    {
        $html = view('partials.notices', ['errors' => new ViewErrorBag])->render();
        $this->assertStringNotContainsString('data-error-summary', $html);
    }

    public function test_sp2_profile_failed_submit_shows_the_one_summary(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->from(route('settings.profile'))->followingRedirects()->post(route('settings.profile.update'), [
            'name' => '',
            'contact_email' => 'not-an-email',
            'contact_phone' => '(212) 555-0401',
            'street' => '9 New Street',
            'city' => 'Albany',
            'state' => 'NY',
            'postal_code' => '12207',
            'country' => 'US',
        ])->assertOk()->getContent();

        // Exactly one summary on the page, in the layout notices
        // region, listing both problems as anchors to their fields.
        $this->assertSame(1, substr_count($html, 'data-error-summary>'));
        $summary = substr($html, strpos($html, 'data-error-summary>'), 1200);
        $this->assertStringContainsString('href="#name"', $summary);
        $this->assertStringContainsString('href="#contact_email"', $summary);
    }

    // SN-1: summary line agrees with the switches in every combination.

    public function test_sn1_summary_agrees_in_every_combination(): void
    {
        // Amendment 38 (Train A3-1): the summary composes from
        // the ONE real switch. The stored weekly/nudge opt-outs
        // are inert (their toggles are removed; no sender reads
        // them), so every combination of them renders the same
        // summary for a given text state.
        foreach ([false, true] as $text) {
            foreach ([false, true] as $weekly) {
                foreach ([false, true] as $nudges) {
                    $business = World::business([
                        'owner_text_notifications' => $text,
                        'weekly_digest_opt_out' => ! $weekly,
                        'nudge_opt_out' => ! $nudges,
                    ]);
                    $owner = World::owner($business);

                    $html = $this->actingAs($owner)->get(route('settings.notifications'))->assertOk()->getContent();
                    $plain = $this->plain($html);

                    $expected = $text
                        ? 'Right now: you get a text and an email when your first referred friend visits. Billing emails and security emails about your account always send.'
                        : 'Right now: no texts about your program. You still get an email when your first referred friend visits. Billing emails and security emails about your account always send.';

                    $this->assertStringContainsString($expected, $plain, "Summary mismatch for text=".json_encode($text)." weekly=".json_encode($weekly)." nudges=".json_encode($nudges));
                    $this->assertStringNotContainsString('weekly summary', $plain);
                    // CHANGED PIN (M-5, Amendment 72): was "no
                    // 'reminder emails' anywhere" (Amendment 38:
                    // no reminder toggle without a sender). M-5
                    // ships the sender (LifecycleMailService) and
                    // its turn-off row with it, so the phrase now
                    // renders as the row's own label; the summary
                    // line above still never mentions it.
                    $this->assertStringContainsString('Setup reminder emails', $plain);
                    $this->assertMatchesRegularExpression(
                        '/<input[^>]*name="setup_reminders"[^>]*checked[^>]*>/',
                        $html,
                        'the setup-reminders switch renders ON while the opt-out column is false',
                    );

                    // The one surviving switch renders the same state.
                    $this->assertMatchesRegularExpression(
                        '/<input[^>]*name="owner_text_notifications"[^>]*'.($text ? 'checked' : '').'[^>]*>/',
                        $html
                    );
                    if (! $text) {
                        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="owner_text_notifications"[^>]*checked/', $html);
                    }
                    // The removed toggles render in no state.
                    $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="(weekly_summary|nudges)"/', $html);
                }
            }
        }
    }

    public function test_sn_notifications_lede_verbatim(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('settings.notifications'))
            ->assertOk()
            ->assertSee('The texts and emails Easy Refer sends you about your program.');
    }

    // SN-2: groups and the always-on billing row (not submittable).

    public function test_sn2_groups_and_billing_row_not_submittable(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('settings.notifications'))->assertOk()->getContent();

        $this->assertStringContainsString('About your program', $html);
        $this->assertStringContainsString('Email', $html);
        $this->assertStringContainsString('Billing emails', $html);
        $this->assertStringContainsString('Always on. Receipts, payment problems, and charge notices always send.', $html);

        // The surviving switch label and description stay verbatim.
        $this->assertStringContainsString('Text me when a referred friend visits', $html);
        $this->assertStringContainsString('Sends one text to your contact phone for your first referral visit. It sends only once.', $html);
        // Amendment 38 (Train A3-1): the weekly and nudge toggles
        // are removed; neither label renders.
        $this->assertStringNotContainsString('Weekly summary email', $html);
        $this->assertStringNotContainsString('Referral reminder nudges', $html);

        // The billing switch is disabled and carries no name at all,
        // so no submission path can alter billing mail.
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*disabled[^>]*checked|checked[^>]*disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="[^"]*billing[^"]*"/', $html);
        // Exactly the real switches are named in the form
        // (Amendment 38: the weekly and nudge toggles are gone;
        // M-5 Amendment 72 adds the second real switch,
        // setup_reminders, with its sender shipped).
        $this->assertSame(2, preg_match_all('/<input[^>]*type="checkbox"[^>]*name="/', $html));
        $this->assertMatchesRegularExpression('/<input[^>]*name="setup_reminders"/', $html);
    }

    // SL-1: bounds rendered from the same config source as validation.

    public function test_sl1_rendered_bounds_equal_validation_bounds(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $dailyMin = (int) config('referrals.velocity_daily_min');
        $dailyMax = (int) config('referrals.velocity_daily_platform_max');
        $monthlyMin = (int) config('referrals.velocity_monthly_min');
        $monthlyMax = (int) config('referrals.velocity_monthly_platform_max');
        $dailyDefault = (int) config('referrals.velocity_daily_default');
        $monthlyDefault = (int) config('referrals.velocity_monthly_default');

        $html = $this->actingAs($owner)->get(route('settings.limits'))->assertOk()->getContent();
        $plain = $this->plain($html);

        $this->assertStringContainsString("Between {$dailyMin} and {$dailyMax}. The starting setting is {$dailyDefault}.", $plain);
        $this->assertStringContainsString("Between {$monthlyMin} and {$monthlyMax}. The starting setting is {$monthlyDefault}.", $plain);
        // The shipped help paragraph stays verbatim.
        $this->assertStringContainsString('Limits keep one person from earning more than this from your store. Extra referrals are saved and held for your review.', $plain);
        $this->assertStringContainsString('How many rewards one customer can earn, so one person cannot run away with your program.', $plain);

        // Validation enforces exactly the rendered range edges.
        $this->actingAs($owner)->post(route('settings.limits.update'), [
            'daily_referral_cap' => $dailyMin - 1, 'monthly_referral_cap' => $monthlyMax + 1,
        ])->assertSessionHasErrors(['daily_referral_cap', 'monthly_referral_cap']);
        $this->actingAs($owner)->post(route('settings.limits.update'), [
            'daily_referral_cap' => $dailyMin, 'monthly_referral_cap' => $monthlyMax,
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('settings.limits.update'), [
            'daily_referral_cap' => $dailyMax, 'monthly_referral_cap' => $monthlyMin,
        ])->assertSessionHasNoErrors();
    }

    // SL-2: worked example figures equal the field values.

    public function test_sl2_example_matches_field_values_at_min_max_and_middle(): void
    {
        $cases = [
            [(int) config('referrals.velocity_daily_min'), (int) config('referrals.velocity_monthly_min')],
            [(int) config('referrals.velocity_daily_platform_max'), (int) config('referrals.velocity_monthly_platform_max')],
            [3, 25],
        ];

        foreach ($cases as [$daily, $monthly]) {
            $business = World::business(['daily_referral_cap' => $daily, 'monthly_referral_cap' => $monthly]);
            $owner = World::owner($business);

            $html = $this->actingAs($owner)->get(route('settings.limits'))->assertOk()->getContent();
            $plain = $this->plain($html);

            $this->assertStringContainsString(
                "At {$daily} a day and {$monthly} a month, one customer can earn up to {$monthly} rewards a month. Referrals past the limit are saved and held for your review.",
                $plain,
                "Example mismatch at daily={$daily} monthly={$monthly}"
            );
            // Live binding: the form carries Alpine models for both fields.
            $this->assertStringContainsString('x-model="daily"', $html);
            $this->assertStringContainsString('x-model="monthly"', $html);
            // Closing link line reaches the review queue.
            $this->assertStringContainsString('href="'.route('reviews').'"', $html);
        }
    }

    // SI-1 / SI-2: index states match their own pages.

    private function cardFor($business, array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'business_id' => $business->id,
            'square_card_id' => 'card_test_1',
            'card_brand' => 'VISA',
            'last4' => '1111',
            'exp_month' => 12,
            'exp_year' => (int) now()->addYears(2)->format('Y'),
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'gap-si-'.$business->id.'-'.uniqid(),
        ], $overrides));
    }

    public function test_si1_full_state_matches_each_settings_page(): void
    {
        $business = World::business([
            'google_review_url' => 'https://g.page/r/ABC123',
            'billing_status' => 'active',
            'friend_reward_text' => '$10 off your first visit',
        ]);
        $owner = World::owner($business);
        $owner->forceFill(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()])->save();
        $this->cardFor($business);

        $html = $this->actingAs($owner)->withSession(['auth.two_factor_passed' => true])->get(route('settings'))->assertOk()->getContent();

        $this->assertStringContainsString('Card on file', $html);
        $this->assertStringContainsString('Link set', $html);
        $this->assertStringContainsString('$10 off your first visit', $html);
        $this->assertStringContainsString('Two-factor on', $html);

        // Same render, the source pages agree.
        $this->actingAs($owner)->get(route('settings.reviews'))->assertOk()->assertSee('Link set');
        $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->assertSee('On file');
        $this->actingAs($owner)->withSession(['auth.two_factor_passed' => true])->get(route('security'))->assertOk()->assertSee('Two-factor authentication is on');
    }

    public function test_si1_no_card_and_reviews_unset_states(): void
    {
        $business = World::business([
            'google_review_url' => null,
            'billing_status' => 'active',
            'friend_reward_text' => null,
        ]);
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();

        $this->assertStringContainsString('No card', $html);
        $this->assertStringContainsString('Not set yet', $html);
        $this->assertStringContainsString('Two-factor off', $html);
        // Rewards unset carries the warning tone.
        $this->assertMatchesRegularExpression('/pill pill-warning[^>]*>\s*Not set yet/', $html);

        $this->actingAs($owner)->get(route('settings.reviews'))->assertOk()->assertSee('Not set (optional)');
        $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->assertSee('No card on file');
    }

    public function test_si1_past_due_state(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $owner = World::owner($business);
        $this->cardFor($business);

        $html = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('Past due', $html);
        $this->assertMatchesRegularExpression('/pill pill-error[^>]*>\s*Past due/', $html);
    }

    public function test_si1_rewards_text_is_truncated_on_the_index(): void
    {
        $long = str_repeat('A very long friend reward text ', 6);
        $business = World::business(['friend_reward_text' => $long]);
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();
        $this->assertStringNotContainsString($long, $html);
        $this->assertStringContainsString('truncate', $html);
    }

    public function test_si2_quiet_states_and_unset_value_has_no_state(): void
    {
        $business = World::business([
            'daily_referral_cap' => 3,
            'monthly_referral_cap' => 25,
            'owner_text_notifications' => true,
            'average_ticket_cents' => 5000,
        ]);
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('3 a day · 25 a month', $html);
        // Amendment 38 (Train A3-1): the index notifications row
        // names the first-visit text switch, never a weekly state.
        $this->assertStringContainsString('First-visit text on', $html);
        $this->assertStringNotContainsString('Weekly email', $html);
        $this->assertStringContainsString('$50.00', $html);

        $business2 = World::business(['owner_text_notifications' => false, 'average_ticket_cents' => null]);
        $owner2 = World::owner($business2);
        $html2 = $this->actingAs($owner2)->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('First-visit text off', $html2);
        // Unset Value renders no state element at all: the Value
        // row's own segment carries no ticket figure.
        $valuePos = strpos($html2, '>Value<');
        $this->assertNotFalse($valuePos);
        $this->assertStringNotContainsString('$', substr($html2, $valuePos, 900));
    }

    public function test_si2_index_rows_stack_state_below_text_on_narrow_widths(): void
    {
        // T2R-01 row geometry: at phone widths the state must wrap
        // to its own line, never crush the label column.
        $business = World::business();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('flex min-h-[48px] flex-wrap items-center gap-3 py-3', $html);
        $this->assertStringContainsString('max-lg:order-4 max-lg:w-full max-lg:pl-[52px]', $html);
        $this->assertStringContainsString('max-lg:order-3', $html);
    }

    // SI-3: query guard.

    public function test_si3_index_query_count_is_bounded_and_card_read_once(): void
    {
        $business = World::business(['google_review_url' => 'https://g.page/r/ABC123']);
        $owner = World::owner($business);
        $this->cardFor($business);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get(route('settings'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $paymentQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'payment_methods'));
        // Exactly the three standing readers, one query each: the
        // checklist fingerprint in this controller, the shell's
        // checklist/badge computation, and SI-1's billing state.
        // A per-row read would add one query per index row (9+).
        $this->assertLessThanOrEqual(3, count($paymentQueries), 'Card state must be read once per consumer, not per row.');
        $this->assertLessThanOrEqual(30, count($queries), 'Settings index query count grew past the guard: '.count($queries));
    }
}
