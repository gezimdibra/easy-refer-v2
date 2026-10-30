<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Mail\DunningPauseWarningMail;
use App\Mail\DunningPaymentFailedMail;
use App\Mail\DunningRecoveredMail;
use App\Mail\DunningReminderMail;
use App\Mail\WelcomeMail;
use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use App\Notifications\EmailChangedNotification;
use App\Services\Billing\MonthlyCharger;
use App\Services\Billing\SettlementService;
use App\Services\Owner\ActionCenter;
use App\Support\BillingForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Train A2 (truth train, Amendment 37): every unit's pins,
 * written test-first against the design pass
 * (train-a2-design-pass.md) plus the sweep-2 folds (Addendum 7).
 * A2-1 forecast family, A2-1x public $99 family, A2-2 closure
 * (ruling C-1), A2-3 text promises, A2-4 onboarding/welcome,
 * A2-5 business-local dates, A2-6 verification-gate reword,
 * A2-7 dunning tail.
 */
class TrainA2TruthTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 7300;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SquarePaymentsGateway::class, new FakeSquarePaymentsGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    // ------------------------------------------------ helpers

    private function card(Business $business, int $expMonth = 12, int $expYear = 2030): PaymentMethod
    {
        return PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_a2_'.$business->id,
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => $expMonth,
            'exp_year' => $expYear,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_a2_'.$business->id,
        ]);
    }

    private function billableEvents(Business $business, int $count): void
    {
        $referrer = World::customer('+1212555'.($this->seq++), 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        for ($i = 0; $i < $count; $i++) {
            $friend = World::customer('+1212555'.($this->seq++), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }
    }

    /** Attempted (billable) campaign text rows, the charger's own evidence rule. */
    private function campaignTexts(Business $business, array $amounts): void
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'recipient_count' => count($amounts),
            'segment_count' => 1,
            'metered_cents' => array_sum($amounts),
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => World::owner($business)->id,
            'sent_at' => now(),
        ]);

        foreach ($amounts as $i => $amount) {
            $customer = World::customer('+1212555'.($this->seq++), 'Cam', 'Paign');
            [$membership] = World::membership($business, $customer);
            $messageId = SmsMessage::create([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'phone_e164' => $customer->phone_e164,
                'purpose' => 'campaign',
                'messaging_service' => 'marketing',
                'template_key' => 'campaign',
                'rendered_body' => 'Spring sale is on',
                'twilio_message_sid' => 'SM_A2_'.$campaign->id.'_'.$i,
                'delivery_state' => 'sent',
                'consent_basis' => 'customer_checkbox',
                'opt_out_state_at_send' => false,
            ])->id;

            SmsCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'membership_id' => $membership->id,
                'customer_id' => $customer->id,
                'phone_e164' => $customer->phone_e164,
                'twilio_message_sid' => 'SM_A2_'.$campaign->id.'_'.$i,
                'sms_message_id' => $messageId,
                'delivery_state' => 'sent',
                'segments' => 1,
                'amount_cents' => $amount,
            ]);
        }
    }

    private function renderMailText(Mailable $mail): string
    {
        $content = $mail->content();

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(view($content->view, $content->with)->render())));
    }

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    // ------------------------------------------------ A2-1 forecast family

    public function test_forecast_combines_visits_and_texts_identically_on_billing_dashboard_and_email(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $this->card($business);
        $this->billableEvents($business, 3);
        $this->campaignTexts($business, [100, 150]);
        $owner = World::owner($business);

        // The forecast preview agrees with the charger's own
        // batch total for the same fixture, to the cent.
        $preview = app(SettlementService::class)->forecastPreview($business->fresh());
        $this->assertSame(600, $preview['visit_cents']);
        $this->assertSame(250, $preview['text_cents']);
        $this->assertSame(850, $preview['total_cents']);
        $this->assertSame(3, $preview['billable_events']);

        $line = 'Counted so far: 3 visits through October 31. Expected charge on October 31: $8.50, including $2.50 in text charges.';
        $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->assertSee($line);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee($line);

        // The email: same total, same composition clause, and
        // ONE charge date, business-local, in body and well.
        Mail::fake();
        Carbon::setTestNow('2026-10-29 12:00:00');
        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        $facts = BillingForecast::emailFacts($business->fresh());
        $this->assertSame('October 31, 2026', $facts['chargeOn']);
        $this->assertSame('$8.50', $facts['amount']);
        $body = BillingForecast::emailBody($business->fresh());
        $this->assertStringContainsString('for an expected charge of $8.50, including $2.50 in text charges', $body);
        $this->assertStringContainsString('runs on October 31.', $body);
    }

    public function test_forecast_total_matches_the_charger_record_for_a_mixed_month(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $this->card($business);
        $this->billableEvents($business, 3);
        $this->campaignTexts($business, [100, 150]);

        $preview = app(SettlementService::class)->forecastPreview($business->fresh());
        $record = app(MonthlyCharger::class)->charge($business->fresh());

        $this->assertNotNull($record);
        $this->assertSame($preview['total_cents'], $record->amount_cents);
    }

    public function test_texts_only_month_renders_the_texts_only_branch_and_email(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $this->card($business);
        $this->campaignTexts($business, [300]);
        $owner = World::owner($business);

        $line = 'Counted so far: $3.00 in text charges through October 31. Expected charge on October 31: $3.00.';
        $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->assertSee($line);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee($line);

        $body = BillingForecast::emailBody($business->fresh());
        $this->assertStringContainsString('Counted so far: $3.00 in text charges through October 31, for an expected charge of $3.00.', $body);
        $this->assertStringContainsString('Text charges from campaigns you send between now and October 31 are added before the charge runs.', $body);
    }

    public function test_no_card_suppresses_forecast_line_and_email_and_burns_no_cycle(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-10-29 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $this->billableEvents($business, 3);
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->assertDontSee('Counted so far:');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('Counted so far:');

        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        Mail::assertNothingSent();
        $this->assertFalse(AuditLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'billing.forecast_email')
            ->exists());

        // A card added inside the same window still gets the
        // email on a later run in the same cycle.
        $this->card($business);
        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        Mail::assertSentCount(1);
    }

    public function test_sub_dollar_combined_total_suppresses_forecast_and_shows_carry_line(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-10-29 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $this->card($business);
        $this->campaignTexts($business, [50]);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'))->assertOk();
        $response->assertDontSee('Counted so far:');
        // BILL-06's condition reads the COMBINED total: a
        // text-only 50-cent month carries forward too.
        $response->assertSee('Your amount so far is under $1, so it carries forward. It joins your next monthly charge.');

        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        Mail::assertNothingSent();
    }

    public function test_expiry_guard_needs_a_real_charge_coming(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $this->card($business, 10, 2026);
        $owner = World::owner($business);

        $keys = collect(app(ActionCenter::class)->items($business->fresh(), $owner))->pluck('key');
        $this->assertFalse($keys->contains('card_expires_before_charge'));

        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        Mail::assertNothingSent();

        // Once a charge at or over the minimum is really
        // coming, the row and the email return.
        $this->billableEvents($business, 3);
        $keys = collect(app(ActionCenter::class)->items($business->fresh(), $owner))->pluck('key');
        $this->assertTrue($keys->contains('card_expires_before_charge'));
        $this->artisan('billing:forecast-reminders')->assertExitCode(0);
        Mail::assertSent(\App\Mail\CardExpiryGuardMail::class);
    }

    public function test_value_panel_names_visit_fees_and_the_months_text_charges(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $this->card($business);
        $this->billableEvents($business, 3);
        $this->campaignTexts($business, [250]);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $response->assertSee('Visit fees so far this month: $6.00');
        $response->assertSee('Text charges so far this month: $2.50');
        $this->assertStringNotContainsString('Fees so far this month:', $response->getContent());
    }

    public function test_billing_plan_panel_and_tile_scope_the_figures(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $this->card($business);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('settings.billing'))->assertOk();
        $response->assertSee('Never more than $99 a month in visit charges.');
        $response->assertSee('Visit fees so far');
        $this->assertStringNotContainsString('Never more than $99 a month.', $response->getContent());
    }

    public function test_statement_text_line_names_each_customer(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $owner = World::owner($business);
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_CAMPAIGN_METERING,
            'amount_cents' => 300,
            'currency' => 'USD',
            'idempotency_key' => 'camp_a2_stmt_'.$business->id,
            'state' => BillingRecord::STATE_SUCCEEDED,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ]);
        $this->campaignTexts($business, [300]);
        SmsCampaignRecipient::query()->update(['billing_record_id' => $record->id]);

        $this->actingAs($owner)->get(route('settings.billing.statement', $record))
            ->assertOk()
            ->assertSee('Texts sent in campaigns this charge covers, at 2 cents per text segment for each customer.')
            ->assertDontSee('at 2 cents per text segment.');
    }

    // ------------------------------------------------ A2-1x public $99 family

    public function test_public_cap_claims_are_scoped_everywhere(): void
    {
        $home = $this->get(route('home'))->assertOk();
        $home->assertSee('Never more than $99 a month in visit charges.');
        $home->assertSee('Never more than $99/month in visit charges');
        $this->assertStringNotContainsString('Never more than $99 a month.', $home->getContent());
        $home->assertSee('Our visit fee, shown above, is the only per-visit charge from us. Bulk texts, if you send them, cost 2 cents per segment for each customer.');
        $home->assertDontSee('is the only charge from us.');

        $vertical = $this->get('/for-cafes')->assertOk();
        $vertical->assertSee('Never more than $99 a month in visit charges.');
        $this->assertStringNotContainsString('Never more than $99 a month.', $vertical->getContent());

        $faq = $this->get(route('faq'))->assertOk();
        $faq->assertSee('Never more than $99 a month in visit charges.');
        $this->assertStringNotContainsString('Never more than $99 a month.', $faq->getContent());
    }

    // ------------------------------------------------ A2-2 closure family (C-1)

    private function heldReward(Business $business): Reward
    {
        $referrer = World::customer('+1212555'.($this->seq++), 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+1212555'.($this->seq++), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        [$reward] = World::reward($membership, $referral, ['hold' => true, 'held_at' => now()]);

        return $reward;
    }

    public function test_close_screen_names_the_held_queue_only_when_holds_exist(): void
    {
        Carbon::setTestNow('2026-10-28 12:00:00');
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('settings.account'))
            ->assertOk()
            ->assertDontSee('waiting for your review')
            ->assertSee('Ending your program turns your QR code off right away. No new visits or texts are charged after you end. Rewards your customers already earned stay good for 30 days after you end, or until their own use by date if that comes first. Your account stays open so you can sign in and see your history. Nothing is deleted.')
            ->assertDontSee('Billing stops after the final reconciliation.');

        $this->heldReward($business);
        $this->heldReward($business);

        // Closure moment would be now (Oct 28 local); the
        // claimability date 30 days out is November 27 local.
        $this->actingAs($owner)->get(route('settings.account'))
            ->assertOk()
            // Amendment 38 (two-key note 2): the count now covers
            // held referrals AND rewards, named by kind; this
            // fixture holds 2 rewards and 0 referrals.
            ->assertSee('You have 2 items waiting for your review: 0 referrals and 2 rewards. You can still release or deny them after you end, from the review queue. Anything you release stays claimable until 30 days after you end.');
    }

    public function test_closed_owner_can_still_release_and_deny_in_the_review_queue(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $reward = $this->heldReward($business);
        $reward2 = $this->heldReward($business);

        $referrer = World::customer('+1212555'.($this->seq++), 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+1212555'.($this->seq++), 'Fri', 'End');
        [$heldReferral] = World::referral($membership, $friend, ['hold' => true, 'held_at' => now()]);

        $business->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->actingAs($owner)->post(route('reviews.rewards.release', ['reward' => $reward->id]));
        $this->assertFalse($reward->fresh()->hold);

        $this->actingAs($owner)->post(route('reviews.rewards.deny', ['reward' => $reward2->id]));
        $this->assertSame(Reward::STATE_DENIED, $reward2->fresh()->state);

        $this->actingAs($owner)->post(route('reviews.referrals.release', ['referral' => $heldReferral->id]));
        $this->assertFalse($heldReferral->fresh()->hold);

        [$heldReferral2] = World::referral($membership, World::customer('+1212555'.($this->seq++), 'Fri', 'End'), ['hold' => true, 'held_at' => now()]);
        $this->actingAs($owner)->post(route('reviews.referrals.deny', ['referral' => $heldReferral2->id]));
        $this->assertSame(Referral::STATE_VOIDED, $heldReferral2->fresh()->state);

        // Every other owner POST stays refused while closed.
        $response = $this->actingAs($owner)->post(route('settings.rewards.update'), [
            'friend_reward_text' => 'New text', 'referrer_reward_text' => '',
            'vesting_days' => 14, 'redemption_window_days' => 30,
        ]);
        $response->assertRedirect(route('dashboard'));
        $this->assertSame('$10 off your first visit', $business->fresh()->friend_reward_text);
    }

    public function test_closed_banner_and_account_line_carry_no_sign_in_deadline_and_local_dates(): void
    {
        // Closed in the US evening: 2026-10-28 01:30 UTC is
        // October 27 in New York; claimability ends 30 days
        // after the closure instant, November 26 local.
        $business = World::business();
        $business->forceFill(['status' => 'closed', 'closed_at' => Carbon::parse('2026-10-28 01:30:00')])->save();
        $owner = World::owner($business);

        $dash = $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $dash->assertSee('Your program has ended. You can still sign in and view your history. Rewards your customers earned stay claimable until Nov 26, 2026. New enrollments and your QR code are off.');
        $this->assertStringNotContainsString('sign in to confirm claims and view your history until', $dash->getContent());

        $account = $this->actingAs($owner)->get(route('settings.account'))->assertOk();
        $account->assertSee('Your program ended on Oct 27, 2026. You can still sign in and view your history. Rewards your customers earned stay claimable until Nov 26, 2026.');
    }

    public function test_faq_closure_answer_matches_the_close_screen(): void
    {
        $this->get(route('faq'))->assertOk()
            ->assertSee('End your program any time in Settings, under Account. Your code turns off right away, we charge one final bill for the visits and texts you already have, and rewards your customers already earned stay good for 30 days, or until their own use by date if that comes first. Your account stays open so you can see your history.')
            ->assertDontSee('Billing stops after a final check of your visits.');
    }

    // ------------------------------------------------ A2-3 text promises

    private function claimSetup(array $rewardOverrides): array
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        [, $claimToken] = World::reward($membership, $referral, $rewardOverrides);

        return [$business, $claimToken];
    }

    public function test_referrer_text_promise_carries_the_opt_out_qualifier_everywhere(): void
    {
        $v2 = 'We will text you when it is ready, unless you have opted out of texts. You can also check this page.';
        [$business, $claimToken] = $this->claimSetup([
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(10),
            'notification_state' => 'queued',
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()->assertSee($v2);

        [, $heldToken] = $this->claimSetup([
            'state' => Reward::STATE_AVAILABLE, 'hold' => true, 'held_at' => now(),
        ]);
        $held = $this->get(route('refer.claim.page', ['token' => $heldToken]))->assertOk();
        $held->assertSee('Your reward is being confirmed by');
        $held->assertSee('Nothing for you to do. '.$v2);
        $held->assertDontSee('This can take a day');
    }

    public function test_share_page_waiting_and_held_blocks_carry_the_same_promise(): void
    {
        $v2 = 'We will text you when it is ready, unless you have opted out of texts. You can also check this page.';
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '+12125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->carryFlowCookie($this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share')));
        $membership = Membership::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        World::reward($membership, $referral, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(10),
            'notification_state' => 'queued',
        ]);

        $this->get(route('refer.share'))->assertOk()->assertSee($v2);

        // Held form of the same block.
        Reward::query()->update(['hold' => true, 'held_at' => now()]);
        $held = $this->get(route('refer.share'))->assertOk();
        $held->assertSee('Nothing for you to do. '.$v2);
        $held->assertDontSee('This can take a day');
    }

    public function test_friend_page_has_no_held_state_and_no_text_promise(): void
    {
        // Option B (2026-10-28): this test's original subject —
        // the REF-CLAIM-07 v2 held rendering ON THE FRIEND PAGE —
        // is retired with that rendering site (Amendment 42).
        // The friend page renders the show state for a pending
        // referral in every case; the held sentences, the text
        // promise, and the day bound are all absent here. (The
        // sentences themselves survive, truthfully, on the
        // referrer's claim page for a held reward.)
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend, ['hold' => true, 'held_at' => now()]);

        $page = $this->get(route('refer.redeem.page', ['token' => $token]))->assertOk();
        $page->assertSee('Show this code to a staff member');
        $page->assertDontSee('Your reward is being confirmed by '.$business->name.'.');
        $page->assertDontSee('Nothing for you to do. Check this page again after the business has reviewed it.');
        $page->assertDontSee('We will text you');
        $page->assertDontSee('This can take a day');
    }

    // RETIRED (gap-at-redemption, owner ruling 2026-10-29):
    // test_too_soon_screen_qualifies_the_immediacy_claim was
    // removed with the too_soon screen it qualified. The claim
    // now completes immediately and the gap is enforced at
    // redemption; see GapAtRedemptionTest and ClaimGapTest.

    // ------------------------------------------------ A2-4 onboarding + welcome

    public function test_onboarding_card_step_states_the_true_removal_and_plan_lines(): void
    {
        $business = World::business(['billing_status' => 'setup_incomplete']);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('onboarding.step', 'card'))->assertOk();
        // RE-DERIVED (activation redesign, census C-08,
        // ACT-CARD-05): removal re-enters the allowance; the
        // line names the pause at the end of the free visits.
        $response->assertSee('You can take your card off any time in Settings → Billing, or end your program any time in Settings → Account. Taking the card off pauses charges. Visits keep counting while your free visits last, and your code pauses when they run out if no card is on file.');
        $response->assertSee('one charge a month for that month\'s visits, never more than $99 in visit charges.', false);
        $response->assertDontSee('turns your code off');
        $this->assertStringNotContainsString('one charge a month, never more than', $response->getContent());
    }

    public function test_welcome_email_branches_on_the_card_not_only_the_email(): void
    {
        // RE-DERIVED (activation redesign, census C-28/C-29):
        // verified, no card, rewards set — the code is LIVE on
        // the free-visit allowance, so the confirmed branch is
        // the ready branch and its button is the QR code. The
        // card branch survives only where the card is the
        // actual blocker: the allowance exhausted
        // (ActivationAllowanceTest P10 pins that mail shape).
        $business = World::business();
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $owner = $owner->fresh();
        $mail = new WelcomeMail($owner);
        $text = $this->renderMailText($mail);
        $this->assertStringContainsString('you never pay more than $99 in a month in visit charges', $text);
        $this->assertStringContainsString('Your email address is confirmed, so your code is ready to go out.', $text);
        $this->assertStringNotContainsString('One step left before your code works: add your card.', $text);
        $content = $mail->content();
        $this->assertSame('Get your QR code', $content->with['actionLabel']);
        $this->assertSame(route('owner.qr'), $content->with['actionUrl']);

        // Verified with a card: the original sentence is true
        // here and stays, with the QR button.
        $this->card($business);
        $mail = new WelcomeMail($owner->fresh());
        $text = $this->renderMailText($mail);
        $this->assertStringContainsString('Your email address is confirmed, so your code is ready to go out.', $text);
        $this->assertSame('Get your QR code', $mail->content()->with['actionLabel']);

        // CHANGED PIN (TS-PM-01, truth sweep 2026-10-29 PM):
        // unverified, scoped plan line. By this point in the
        // test the business has BOTH its card and its friend
        // reward text, so under the old premise verify read as
        // the one step left; verification gates nothing, so
        // the true form is zero steps left, with the
        // verification ask carried as its own sentence.
        $owner->forceFill(['email_verified_at' => null])->save();
        $text = $this->renderMailText(new WelcomeMail($owner->fresh()));
        $this->assertStringContainsString('No steps left before your code works.', $text);
        $this->assertStringContainsString('Verify this email address so your receipts and password resets reach you.', $text);
        $this->assertStringNotContainsString('before your code works: verify', $text);
        $this->assertStringContainsString('you never pay more than $99 in a month in visit charges', $text);
    }

    // ------------------------------------------------ A2-5 dates

    public function test_rewards_list_claimed_date_is_business_local_on_both_layouts(): void
    {
        // Claimed 2026-10-28 00:30 UTC = October 27 in New York.
        $business = World::business();
        $owner = World::owner($business);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        World::reward($membership, $referral, [
            'state' => Reward::STATE_CLAIMED,
            'claimed_at' => Carbon::parse('2026-10-28 00:30:00'),
        ]);

        $response = $this->actingAs($owner)->get(route('rewards.index'))->assertOk();
        $response->assertSee('Claimed Oct 27, 2026');
        $response->assertSee('claimed Oct 27, 2026');
        $response->assertDontSee('Oct 28, 2026');
    }

    public function test_referrals_list_dates_are_business_local(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => Carbon::parse('2026-10-28 00:30:00'),
        ]);
        $referral->forceFill(['created_at' => Carbon::parse('2026-10-28 00:30:00')])->saveQuietly();

        $response = $this->actingAs($owner)->get(route('referrals.index'))->assertOk();
        $response->assertSee('Oct 27, 2026');
        $this->assertStringNotContainsString('Oct 28, 2026', $response->getContent());
    }

    // ------------------------------------------------ A2-6 verification-gate reword

    public function test_unverified_owner_with_a_card_is_live_and_no_surface_claims_a_gate(): void
    {
        $business = World::business();
        $this->card($business);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => null])->save();
        $owner = $owner->fresh();

        // The truth the copy must match: no verification gate
        // exists; the flow is live on status + billing alone.
        $this->assertTrue($business->fresh()->flowIsActive());

        $truth = 'Verify it so your receipts and password resets reach you.';

        $gate = $this->actingAs($owner)->get(route('verification.notice'))->assertOk();
        $gate->assertSee($truth);
        $gate->assertDontSee('stay off');

        $account = $this->actingAs($owner)->get(route('settings.account'))->assertOk();
        $account->assertSee('This email is not verified yet. '.$truth);
        $account->assertDontSee('stay off until');

        $dash = $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $dash->assertSee($truth);
        $dash->assertDontSee('to activate your QR code and billing');

        $lines = (new EmailChangedNotification('new@example.test'))->toMail($owner)->introLines;
        $joined = implode(' ', $lines);
        $this->assertStringContainsString($truth, $joined);
        $this->assertStringNotContainsString('can resume', $joined);
    }

    // ------------------------------------------------ A2-7 dunning tail

    private function pastDueBusiness(): Business
    {
        $this->travelTo(Carbon::parse('2026-03-10 12:00:00'));
        $business = World::business([
            'billing_status' => 'active',
            'square_customer_id' => 'sq_customer_a2',
            'free_visits_remaining' => 0,
        ]);
        BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 4200,
            'currency' => 'USD',
            'idempotency_key' => 'perref_'.$business->id.'_a2_'.Str::random(6),
            'state' => BillingRecord::STATE_PENDING,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);
        app(\App\Services\Billing\SquareWebhookHandler::class)->handle([
            'type' => 'payment.updated',
            'data' => ['object' => ['payment' => [
                'id' => 'sq_pay_a2_failed', 'status' => 'FAILED', 'customer_id' => 'sq_customer_a2',
            ]]],
        ]);
        $this->assertSame('past_due', $business->fresh()->billing_status);

        return $business->fresh();
    }

    public function test_late_first_dunning_run_sends_the_paused_form_not_a_past_grace_date(): void
    {
        Mail::fake();
        $this->pastDueBusiness();

        // The episode's grace ended March 17; the first run
        // happens March 20. All three stages are due at once,
        // and every one must carry the paused truth.
        $this->travelTo(Carbon::parse('2026-03-20 12:00:00'));
        $this->artisan('billing:dunning')->assertExitCode(0);
        Mail::assertSentCount(3);

        $texts = [];
        Mail::assertSent(DunningPaymentFailedMail::class, function (DunningPaymentFailedMail $mail) use (&$texts) {
            $texts['day0'] = $this->renderMailText($mail);

            return true;
        });
        Mail::assertSent(DunningReminderMail::class, function (DunningReminderMail $mail) use (&$texts) {
            $texts['day3'] = $this->renderMailText($mail);
            $this->assertSame('Reminder: your Easy Refer payment is still due', $mail->envelope()->subject);

            return true;
        });
        Mail::assertSent(DunningPauseWarningMail::class, function (DunningPauseWarningMail $mail) use (&$texts) {
            $texts['day6'] = $this->renderMailText($mail);
            $this->assertSame('Your Easy Refer payment is still due', $mail->envelope()->subject);

            return true;
        });

        foreach ($texts as $stage => $text) {
            $this->assertStringContainsString('Your QR code and customer links are paused until you update your card.', $text, $stage);
            $this->assertStringContainsString('$42', $text, $stage);
            $this->assertStringNotContainsString('keeps working', $text, $stage);
            $this->assertStringNotContainsString('Keeps working', $text, $stage);
            $this->assertStringNotContainsString('March 17', $text, $stage);
            $this->assertStringNotContainsString('new signups pause', $text, $stage);
        }
    }

    public function test_recovered_email_branches_by_record_composition(): void
    {
        $business = World::business(['free_visits_remaining' => 0, 'average_ticket_cents' => 1000]);

        // Text-only record: no "0 visits, $0.00" about a text charge.
        $textRecord = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_CAMPAIGN_METERING,
            'amount_cents' => 300,
            'currency' => 'USD',
            'idempotency_key' => 'camp_a2_rec_'.$business->id,
            'state' => BillingRecord::STATE_SUCCEEDED,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ]);
        $this->campaignTexts($business, [300]);
        SmsCampaignRecipient::query()->update(['billing_record_id' => $textRecord->id]);

        $text = $this->renderMailText(new DunningRecoveredMail($business->fresh(), $textRecord));
        $this->assertStringContainsString('text campaigns', $text);
        $this->assertStringContainsString('$3 in text charges', $text);
        $this->assertStringNotContainsString('0 visits that counted', $text);
        $this->assertStringNotContainsString('$0.00', $text);

        // Mixed record: both legs named, and the estimate is
        // scoped to the record's own visits (2 x $10), not the
        // whole month (a third, unclaimed visit sits in the
        // same month and must not inflate the figure).
        $mixed = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 550,
            'currency' => 'USD',
            'idempotency_key' => 'perref_a2_rec_'.$business->id,
            'state' => BillingRecord::STATE_SUCCEEDED,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ]);
        $referrer = World::customer('+1212555'.($this->seq++), 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        foreach ([true, true, false] as $claimed) {
            $friend = World::customer('+1212555'.($this->seq++), 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
                'billing_record_id' => $claimed ? $mixed->id : null,
            ]);
        }
        SmsCampaignRecipient::query()
            ->where('billing_record_id', $textRecord->id)
            ->update(['billing_record_id' => $mixed->id, 'amount_cents' => 150]);
        // The mixed record's text leg is $1.50 of its $5.50.

        $text = $this->renderMailText(new DunningRecoveredMail($business->fresh(), $mixed));
        $this->assertStringContainsString('This charge covered 2 visits that counted, worth about $20 in estimated sales, based on your typical spend per visit (estimate), plus $1.50 in text charges.', $text);
    }
}
