<?php

namespace Tests\Feature;

use App\Contracts\MarketingSmsGateway;
use App\Mail\DunningPauseWarningMail;
use App\Mail\DunningPaymentFailedMail;
use App\Mail\DunningReminderMail;
use App\Mail\WelcomeMail;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\StepUpService;
use App\Services\Campaigns\CampaignSender;
use App\Services\Referral\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * TRUTH TRAIN A5 (Sweep-03, 2026-10-29): the state-blind /
 * unwalked-state findings TS3-01, TS3-02, TS3-03, TS3-13,
 * the TS3-09 count-1 pluralization siblings the f7419d6
 * grammar lane swept and left, and the LOWS batch (TS3-04
 * email titles, TS3-05 dunning textless sufficiency past
 * grace, TS3-06 MAIL-01 step count, TS3-07 reset expiry
 * date, TS3-08 SD-06 conditioned promise, TS3-10 WB-03
 * printed segment definition). Every pin renders the
 * surface in the state that used to lie and asserts the
 * true sentence, with the old sentence pinned absent;
 * control states pin the shipped forms byte-identical.
 * Copy record: Amendment 52.
 */
class TruthSweep03FixTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 4400;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
        Queue::fake();
        $this->now = Carbon::parse('2026-06-30 12:00:00');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /** Visible text only: tags stripped, entities decoded, whitespace collapsed. */
    private function visibleText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function member(Business $business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    private function lapsed(Business $business, int $daysAgo = 60): Membership
    {
        $m = $this->member($business);
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$referral] = World::referral($m, World::customer($phone));
        $referral->forceFill([
            'state' => Referral::STATE_REDEEMED,
            'created_at' => now()->subDays($daysAgo),
            'redeemed_at' => now()->subDays($daysAgo - 2),
        ])->save();
        BillingEvent::create([
            'business_id' => $business->id, 'referral_id' => $referral->id,
            'amount_cents' => 200, 'coverage' => 'billable',
        ]);

        return $m;
    }

    /** A prior campaign touch (a recipient row) for F1 / cooldown fixtures. */
    private function touch(Business $business, Membership $m, int $daysAgo, bool $winback = false): void
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => $winback ? 'It has been a while since you sent a friend our way. Your referral link still works. Share it with a friend: when they visit, you both get a reward.' : 'Earlier sale',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => World::owner($business)->id,
            'audience_segment' => $winback ? 'lapsed_referrers' : null,
            'sent_at' => now()->subDays($daysAgo),
        ]);
        $row = SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $m->id,
            'customer_id' => $m->customer_id,
            'phone_e164' => $m->customer->phone_e164,
            'delivery_state' => SmsCampaignRecipient::STATE_DELIVERED,
            'segments' => 2,
            'amount_cents' => 4,
        ]);
        $at = $this->now->copy()->subDays($daysAgo);
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        $campaign->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    /** Four touches, the newest 8 days out: the F1 cap bucket, never spacing. */
    private function capOut(Business $business, Membership $m): void
    {
        foreach ([28, 21, 14, 8] as $daysAgo) {
            $this->touch($business, $m, $daysAgo);
        }
    }

    private function wholeListDraft(Business $business): SmsCampaign
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'June sale',
            'status' => SmsCampaign::STATUS_DRAFT, 'created_by' => World::owner($business)->id,
        ]);
        app(CampaignSender::class)->stampFigures($campaign);

        return $campaign->fresh();
    }

    private function segmentDraft(Business $business): SmsCampaign
    {
        $this->actingAs(World::owner($business))->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);

        return SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();
    }

    private function confirmVisible(Business $business, SmsCampaign $campaign): string
    {
        $response = $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $campaign));
        $response->assertOk();

        return $this->visibleText($response->getContent());
    }

    private function createVisible(Business $business): string
    {
        $response = $this->actingAs(World::owner($business))->get(route('campaigns.create'));
        $response->assertOk();

        return $this->visibleText($response->getContent());
    }

    private function card(Business $business): void
    {
        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_a5_'.$business->id,
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_a5_'.$business->id,
        ]);
    }

    // ------------------------------------------------ TS3-02

    public function test_ts302_segment_f1_only_zero_names_only_the_segment_group_at_one(): void
    {
        $business = World::business();
        $skipped = $this->lapsed($business);
        $this->touch($business, $skipped, 3); // general touch: F1 spacing only, no win-back cooldown

        $visible = $this->confirmVisible($business, $this->segmentDraft($business));

        $this->assertStringContainsString(
            'Total $0.00 for 0 customers. 1 past referrer got a campaign text too recently.',
            $visible
        );
        $this->assertStringNotContainsString(
            'Every customer who agreed to texts got a campaign text too recently.',
            $visible
        );
    }

    public function test_ts302_segment_f1_only_zero_is_plural_at_two(): void
    {
        $business = World::business();
        foreach ([3, 4] as $daysAgo) {
            $skipped = $this->lapsed($business);
            $this->touch($business, $skipped, $daysAgo);
        }

        $visible = $this->confirmVisible($business, $this->segmentDraft($business));

        $this->assertStringContainsString(
            'Total $0.00 for 0 customers. 2 past referrers got a campaign text too recently.',
            $visible
        );
        $this->assertStringNotContainsString(
            'Every customer who agreed to texts got a campaign text too recently.',
            $visible
        );
    }

    public function test_ts302_whole_list_f1_only_zero_keeps_the_whole_list_sentence(): void
    {
        $business = World::business();
        $skipped = $this->member($business);
        $this->touch($business, $skipped, 3);

        $visible = $this->confirmVisible($business, $this->wholeListDraft($business));

        $this->assertStringContainsString(
            'Total $0.00 for 0 customers. Every customer who agreed to texts got a campaign text too recently.',
            $visible
        );
    }

    // ------------------------------------------------ TS3-13

    public function test_ts313_segment_inactive_partially_capped_confirm_never_says_you_can_send(): void
    {
        config(['campaigns.daily_cap' => 2]);
        $business = World::business();
        $this->lapsed($business);
        $this->lapsed($business);
        $this->lapsed($business);
        $campaign = $this->segmentDraft($business);

        // Flow goes inactive after the draft exists; the
        // branch chain suppresses the send form for segment
        // drafts. RE-DERIVED (activation redesign, 2026-10-30):
        // billing 'setup_incomplete' no longer makes the flow
        // inactive (the allowance), so the state is entered
        // through the reward leg instead.
        $business->forceFill(['friend_reward_text' => null])->save();

        $visible = $this->confirmVisible($business->fresh(), $campaign);

        $this->assertStringNotContainsString('You can send 2 of 3 today', $visible);
        $this->assertStringContainsString('This draft stays saved.', $visible);
    }

    public function test_ts313_segment_active_partially_capped_confirm_keeps_the_cap_line(): void
    {
        config(['campaigns.daily_cap' => 2]);
        $business = World::business();
        $this->lapsed($business);
        $this->lapsed($business);
        $this->lapsed($business);

        $visible = $this->confirmVisible($business, $this->segmentDraft($business));

        $this->assertStringContainsString('You can send 2 of 3 today. The rest will not be sent.', $visible);
    }

    public function test_ts313_whole_list_inactive_partially_capped_confirm_keeps_the_cap_line(): void
    {
        config(['campaigns.daily_cap' => 1]);
        // Textless whole-list business: warn-and-allow; it
        // CAN send, so the cap line stays true here.
        $business = World::business(['friend_reward_text' => null]);
        $this->member($business);
        $this->member($business);

        $visible = $this->confirmVisible($business, $this->wholeListDraft($business));

        $this->assertStringContainsString('You can send 1 of 2 today. The rest will not be sent.', $visible);
    }

    // ------------------------------------------------ TS3-01

    public function test_ts301_closure_voided_referral_gets_the_no_cause_fee_line(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550411', 'Ana', 'Ray'));
        [$pending] = World::referral($membership, World::customer('+12125550412', 'Fay', 'One'));

        $this->actingAs($owner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.account.close'), ['confirm' => '1'])
            ->assertRedirect(route('dashboard'));
        $this->assertSame(Referral::STATE_VOIDED, $pending->fresh()->state);

        $page = $this->actingAs(World::owner($business->fresh()))->get(route('referrals.show', $pending));
        $page->assertOk();
        $page->assertSee('Fee: $0.00. No visit happened, so there is no fee.');
        $page->assertDontSee('denied before a visit');
    }

    public function test_ts301_owner_denied_referral_keeps_the_denied_fee_line(): void
    {
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550421', 'Ana', 'Ray'));
        // BREAKER D-2: an owner deny is the resolution of a held
        // review, so the denied referral is fixtured as held.
        [$pending] = World::referral($membership, World::customer('+12125550422', 'Fay', 'One'), ['hold' => true, 'held_at' => now(), 'hold_rules' => ['review']]);

        $this->assertTrue(app(RedemptionService::class)->denyReferral($pending));
        $this->assertSame(Referral::STATE_VOIDED, $pending->fresh()->state);

        $page = $this->actingAs(World::owner($business))->get(route('referrals.show', $pending->fresh()));
        $page->assertOk();
        $page->assertSee('Fee: $0.00. This referral was denied before a visit, so there is no fee.');
    }

    // ------------------------------------------------ TS3-03

    public function test_ts303_closed_business_remove_card_modal_drops_the_card_action_clauses(): void
    {
        $business = World::business(['status' => 'closed', 'closed_at' => now()]);
        $this->card($business);

        $page = $this->actingAs(World::owner($business))->get(route('settings.billing'));
        $page->assertOk();
        $page->assertSee('Remove this card? Your QR code and customer links are off. Visits are not counting and rewards are not being earned while your program is off. The amount you owe stays on your account.');
        $page->assertDontSee('Charges pause until you add a card');
    }

    public function test_ts303_disabled_business_remove_card_modal_keeps_the_pause_until_card_form(): void
    {
        $business = World::business(['status' => 'disabled']);
        $this->card($business);

        $page = $this->actingAs(World::owner($business))->get(route('settings.billing'));
        $page->assertOk();
        $page->assertSee('Remove this card? Your QR code and customer links are off. Visits are not counting and rewards are not being earned while your program is off. Charges pause until you add a card, and the amount you owe stays on your account.');
    }

    // ------------------------------------------------ TS3-09 siblings

    public function test_ts309_fq03_cap_clause_is_singular_at_one(): void
    {
        $business = World::business();
        $capped = $this->member($business);
        $this->capOut($business, $capped);
        $this->member($business);

        $visible = $this->confirmVisible($business, $this->wholeListDraft($business));

        $this->assertStringContainsString(
            '1 customer has had 4 campaign texts in the last 30 days, the most we send, and are skipped.',
            $visible
        );
        $this->assertStringNotContainsString('1 customers have had', $visible);
    }

    public function test_ts309_branch4_pair_is_singular_at_one_and_one(): void
    {
        $business = World::business();
        $f1Only = $this->lapsed($business);
        $this->touch($business, $f1Only, 3);
        $cooldownOnly = $this->lapsed($business);
        $this->touch($business, $cooldownOnly, 30, winback: true);

        $visible = $this->confirmVisible($business, $this->segmentDraft($business));

        $this->assertStringContainsString(
            'Total $0.00 for 0 customers. 1 past referrer got a campaign text too recently.',
            $visible
        );
        $this->assertStringContainsString(
            '1 other past referrer was sent a win-back text in the last 90 days.',
            $visible
        );
        $this->assertStringNotContainsString('1 past referrers got', $visible);
        $this->assertStringNotContainsString('1 other past referrers were sent', $visible);
    }

    public function test_ts309_total_scope_and_button_are_singular_at_one(): void
    {
        $business = World::business();
        $this->member($business);

        $response = $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $this->wholeListDraft($business)));
        $response->assertOk();
        $visible = $this->visibleText($response->getContent());

        $this->assertStringContainsString('Sending to 1 customer who agreed to receive messages.', $visible);
        $this->assertStringContainsString('for 1 customer. Once sent, this text cannot be unsent.', $visible);
        $this->assertStringContainsString('Send to 1 customer', $response->getContent());
        $this->assertStringNotContainsString('1 customers', $visible);
    }

    public function test_ts309_capped_total_is_singular_at_one_fit(): void
    {
        config(['campaigns.daily_cap' => 1]);
        $business = World::business();
        $this->member($business);
        $this->member($business);

        $visible = $this->confirmVisible($business, $this->wholeListDraft($business));

        $this->assertStringContainsString('for 1 customer today. The other 1 will not be sent.', $visible);
        $this->assertStringNotContainsString('for 1 customers today', $visible);
    }

    public function test_ts309_composer_scope_line_and_its_alpine_twin_are_singular_at_one(): void
    {
        $business = World::business();
        $this->member($business);

        $response = $this->actingAs(World::owner($business))->get(route('campaigns.create'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Sending to 1 customer who agreed to receive messages.', $this->visibleText($html));
        $this->assertStringContainsString("(recipients === 1 ? 'customer' : 'customers')", $html);
        $this->assertStringNotContainsString('Sending to 1 customers', $this->visibleText($html));
    }

    public function test_ts309_composer_wb04_segment_line_is_singular_at_one(): void
    {
        $business = World::business();
        $this->lapsed($business);

        $visible = $this->createVisible($business);

        $this->assertStringContainsString('1 past referrer can get this text now.', $visible);
        $this->assertStringNotContainsString('1 past referrers can get', $visible);
    }

    public function test_ts309_composer_wb06_cooldown_clause_verb_is_singular_at_one(): void
    {
        $business = World::business();
        $cooled = $this->lapsed($business);
        $this->touch($business, $cooled, 10, winback: true);

        $visible = $this->createVisible($business);

        $this->assertStringContainsString('No past referrers can get this text now.', $visible);
        $this->assertStringContainsString('1 was sent a win-back text in the last 90 days.', $visible);
        $this->assertStringNotContainsString('1 were sent a win-back text', $visible);
    }

    // ------------------------------------------------ TS3-10

    public function test_ts310_printed_segment_definition_names_the_operative_legs(): void
    {
        $business = World::business();
        $this->lapsed($business);

        $visible = $this->createVisible($business);

        $this->assertStringContainsString(
            'Past referrers are customers who sent a friend who visited, whose referral link is still active and who have no visit on the way, and who have not sent a new referral in 45 days or more.',
            $visible
        );
    }

    public function test_ts310_wb05_zero_form_is_scoped_to_who_can_get_the_text(): void
    {
        $business = World::business();
        // A proven referrer whose token was revoked: by the
        // printed definition a past referrer gone quiet, but
        // outside the code's segment; the zero form must not
        // claim nobody is quiet.
        $revoked = $this->lapsed($business);
        $revoked->forceFill(['share_token_state' => 'revoked'])->save();

        $visible = $this->createVisible($business);

        $this->assertStringContainsString('No past referrers can get a win-back text right now.', $visible);
        $this->assertStringNotContainsString('No past referrers are quiet right now.', $visible);
    }

    // ------------------------------------------------ TS3-08

    public function test_ts308_quiet_day_card_conditions_the_prompt_promise(): void
    {
        $business = World::business();

        $visible = $this->createVisible($business);

        $this->assertStringContainsString(
            'Pick the day your shop is usually quietest. On that day, when your program is active and enough customers can get texts, we will set up a ready-made text for your customers in your Action Center. You always review it before anything sends.',
            $visible
        );
        $this->assertStringNotContainsString(
            'On that day, we will set up a ready-made text for your customers in your Action Center.',
            $visible
        );
    }

    // ------------------------------------------------ TS3-04 + TS3-06 (welcome)

    private function mailTitle(string $html): string
    {
        $this->assertSame(1, preg_match('/<title>(.*?)<\/title>/', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    public function test_ts304_ts306_welcome_title_and_count_follow_the_render_facts(): void
    {
        // CHANGED PIN (TS-PM-01, truth sweep 2026-10-29 PM):
        // two ACTIVATION steps left (unverified, no card, no
        // friend reward text). Was three under TS3-06, counting
        // verification; verification gates nothing, so the
        // count names the two real legs, in the subject, the
        // title, and the body's lead line, and the verification
        // ask rides as its own sentence after the count.
        $unverified = User::factory()->unverified()->create();
        $mail = new WelcomeMail($unverified);
        // RE-DERIVED (activation redesign, census C-28): the
        // count is the reward leg alone here (the card counts
        // only at exhaustion), so the one-step form follows
        // the render facts in subject, title, and body alike.
        $this->assertSame(
            'Welcome to Easy Refer: one step left before your code works',
            $mail->envelope()->subject
        );
        $html = $mail->render();
        $this->assertSame($mail->envelope()->subject, $this->mailTitle($html));
        $this->assertStringContainsString(
            'One step left before your code works: set your rewards.',
            $this->visibleText($html)
        );
        $this->assertStringContainsString(
            'Verify this email address so your receipts and password resets reach you.',
            $this->visibleText($html)
        );
        // (The one-step form IS the render now; the stale
        // two-step form is what must not leak.)
        $this->assertStringNotContainsString('2 steps left', $html);
        $this->assertStringNotContainsString('before your code works: verify', $this->visibleText($html));

        // One step left (verified, no card, no friend text):
        // the reward. The card is not a step before the code
        // works while free visits remain (census C-28).
        $user2 = User::factory()->create();
        $mail2 = new WelcomeMail($user2->fresh());
        $this->assertSame(
            'Welcome to Easy Refer: one step left before your code works',
            $mail2->envelope()->subject
        );
        $html2 = $mail2->render();
        $this->assertSame($mail2->envelope()->subject, $this->mailTitle($html2));
        $this->assertStringContainsString(
            'Your email address is confirmed. One step left before your code works: set your rewards.',
            $this->visibleText($html2)
        );

        // Fully set up: the plain subject, and the title
        // agrees (the static one-step title is gone).
        $ready = User::factory()->create();
        $ready->business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        $this->card($ready->business);
        $mail3 = new WelcomeMail($ready->fresh());
        $this->assertSame('Welcome to Easy Refer', $mail3->envelope()->subject);
        $this->assertSame('Welcome to Easy Refer', $this->mailTitle($mail3->render()));
    }

    public function test_ts306_welcome_one_step_form_survives_when_exactly_one_step_remains(): void
    {
        // RE-DERIVED (activation redesign, census C-28):
        // verified, friend text set, no card used to be
        // exactly the card step; under the allowance gate
        // that state is ZERO steps (the code is live). The
        // one-step form's surviving state is the same
        // business with its allowance spent: exactly the card.
        $business = World::business();
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $tsReferrer = World::customer('+12125551401', 'Reg', 'Ular');
        [$tsMembership] = World::membership($business, $tsReferrer);
        for ($i = 0; $i < 5; $i++) {
            $tsFriend = World::customer('+1212555141'.(string) $i, 'Fri', 'End');
            [$tsReferral] = World::referral($tsMembership, $tsFriend);
            \App\Models\BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $tsReferral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }
        $mail = new WelcomeMail($owner->fresh());
        $this->assertSame(
            'Welcome to Easy Refer: one step left before your code works',
            $mail->envelope()->subject
        );
        $this->assertStringContainsString(
            'Your email address is confirmed. One step left before your code works: add your card.',
            $this->visibleText($mail->render())
        );
    }

    // ------------------------------------------------ TS3-04 + TS3-05 (dunning)

    /** @return array{0: DunningPaymentFailedMail, 1: DunningReminderMail, 2: DunningPauseWarningMail} */
    private function dunningMails(Business $business): array
    {
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 4200,
            'currency' => 'USD',
            'idempotency_key' => 'a5_'.uniqid(),
            'state' => BillingRecord::STATE_PENDING,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);
        $graceEnd = Carbon::parse('2026-03-17 12:00:00');

        return [
            new DunningPaymentFailedMail($business, $record, $graceEnd),
            new DunningReminderMail($business, $record, $graceEnd),
            new DunningPauseWarningMail($business, $record, $graceEnd),
        ];
    }

    private function mailText(object $mail): string
    {
        $content = $mail->content();

        return $this->visibleText(view($content->view, $content->with)->render());
    }

    public function test_ts304_dunning_titles_carry_the_textless_leg(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00'); // inside grace
        $business = World::business(['billing_status' => 'past_due', 'friend_reward_text' => null]);
        [, $reminder, $pause] = $this->dunningMails($business);

        $this->assertSame(
            'Reminder: your Easy Refer payment is still due',
            $this->mailTitle($reminder->render())
        );
        $this->assertSame(
            'Your Easy Refer payment is still due',
            $this->mailTitle($pause->render())
        );

        // Control: in-grace with text: the dated titles stay.
        $business2 = World::business(['billing_status' => 'past_due']);
        [, $reminder2, $pause2] = $this->dunningMails($business2);
        $this->assertSame(
            'Reminder: update your card by Mar 17, 2026',
            $this->mailTitle($reminder2->render())
        );
        $this->assertSame(
            'Your Easy Refer billing pauses on Mar 17, 2026',
            $this->mailTitle($pause2->render())
        );
        Carbon::setTestNow($this->now);
    }

    public function test_ts305_textless_past_grace_names_both_steps_in_all_three_dunning_mails(): void
    {
        Carbon::setTestNow('2026-03-20 12:00:00'); // past the grace end: paused
        $business = World::business(['billing_status' => 'past_due', 'friend_reward_text' => null]);
        foreach ($this->dunningMails($business) as $mail) {
            $text = $this->mailText($mail);
            $this->assertStringContainsString(
                'This code is not active yet. Set your friend reward and update your card to turn it on.',
                $text,
                class_basename($mail)
            );
            $this->assertStringNotContainsString(
                'Set your friend reward to turn it on.',
                $text,
                class_basename($mail)
            );
        }
        Carbon::setTestNow($this->now);
    }

    public function test_ts305_textless_in_grace_keeps_the_single_step_sentence(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00'); // inside grace
        $business = World::business(['billing_status' => 'past_due', 'friend_reward_text' => null]);
        foreach ($this->dunningMails($business) as $mail) {
            $this->assertStringContainsString(
                'This code is not active yet. Set your friend reward to turn it on.',
                $this->mailText($mail),
                class_basename($mail)
            );
        }
        Carbon::setTestNow($this->now);
    }

    // ------------------------------------------------ TS3-07

    public function test_ts307_reset_expiry_line_carries_its_date(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00');
        $user = User::factory()->create();
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => hash('sha256', 'a5-token'),
            'created_at' => now()->subMinutes(45),
        ]);

        $mail = (new ResetPasswordNotification('a5-token'))->toMail($user);
        $text = implode(' ', array_merge($mail->introLines, $mail->outroLines));

        // Created 11:15 UTC + 60m = 12:15 PM UTC = 8:15 AM
        // business-local (EDT) on Mar 10: time AND date.
        $this->assertStringContainsString('will expire at 8:15 AM on Mar 10.', $text);
        Carbon::setTestNow($this->now);
    }
}
