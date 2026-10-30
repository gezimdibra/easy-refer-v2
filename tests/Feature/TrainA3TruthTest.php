<?php

namespace Tests\Feature;

use App\Contracts\MarketingSmsGateway;
use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\ConsentEvent;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\ReferralEvent;
use App\Models\SmsCampaign;
use App\Notifications\AccountUnlockNotification;
use App\Notifications\LoginAttemptAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Train A3 (truth train, Amendment 38): every unit's pins,
 * written test-first against the design pass
 * (train-a3-design-pass.md) plus Addenda 8/9/10/12 of the
 * ranked backlog. Pins assert RENDERING, not just behavior.
 */
class TrainA3TruthTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 9100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SquarePaymentsGateway::class, new FakeSquarePaymentsGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    private function card(Business $business): PaymentMethod
    {
        return PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_a3_'.$business->id,
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_a3_'.$business->id,
        ]);
    }

    private function member(Business $business, array $consent = ['sms_consent' => true, 'consent_source' => 'customer_checkbox']): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill($consent)->save();

        return $membership->fresh();
    }

    // ------------------------------------------------ A3-1 notifications

    public function test_notifications_page_renders_only_the_true_sentences_with_text_on(): void
    {
        $business = World::business(['owner_text_notifications' => true]);
        $page = $this->actingAs(World::owner($business))->get(route('settings.notifications'));

        $page->assertOk();
        $page->assertSee('Right now: you get a text and an email when your first referred friend visits. Billing emails and security emails about your account always send.');
        $page->assertSee('Text me when a referred friend visits');
        $page->assertSee('Billing emails');
        $page->assertDontSee('Weekly summary email');
        $page->assertDontSee('Referral reminder nudges');
        $page->assertDontSee('no texts or emails about your program');
    }

    public function test_notifications_page_all_off_summary_still_names_the_first_visit_email(): void
    {
        $business = World::business(['owner_text_notifications' => false]);
        $page = $this->actingAs(World::owner($business))->get(route('settings.notifications'));

        $page->assertOk();
        $page->assertSee('Right now: no texts about your program. You still get an email when your first referred friend visits. Billing emails and security emails about your account always send.');
        $page->assertDontSee('Weekly summary');
        // CHANGED PIN (M-5, Amendment 72): was assertDontSee
        // 'reminder emails' (Amendment 38: no reminder toggle
        // without a sender). M-5 ships the lifecycle sender and
        // its Setup reminder emails turn-off row together, so
        // the row's label now renders; the summary line above
        // still never mentions reminders.
        $page->assertSee('Setup reminder emails');
    }

    public function test_settings_index_names_the_first_visit_text_state_never_a_weekly_state(): void
    {
        $on = World::business(['owner_text_notifications' => true]);
        $this->actingAs(World::owner($on))->get(route('settings'))
            ->assertOk()->assertSee('First-visit text on')->assertDontSee('Weekly email');

        $off = World::business(['owner_text_notifications' => false]);
        $this->actingAs(World::owner($off))->get(route('settings'))
            ->assertOk()->assertSee('First-visit text off')->assertDontSee('Weekly email');
    }

    public function test_notifications_update_still_stores_the_surviving_switch(): void
    {
        $business = World::business(['owner_text_notifications' => false]);
        $this->actingAs(World::owner($business))->post(route('settings.notifications.update'), [
            'owner_text_notifications' => '1',
        ])->assertRedirect();

        $this->assertTrue((bool) $business->fresh()->owner_text_notifications);
    }

    // ------------------------------------------------ A3-2 claims + delivery promises

    public function test_limits_helpers_use_recommendation_voice_not_population_claims(): void
    {
        $business = World::business();
        $page = $this->actingAs(World::owner($business))->get(route('settings.limits'));

        $page->assertOk();
        $page->assertSee('The starting setting is');
        $page->assertDontSee('Most stores leave this');
    }

    public function test_pack_headings_and_notes_drop_unverifiable_populations(): void
    {
        $business = World::business(['business_type' => 'barber', 'friend_reward_text' => null, 'referrer_reward_text' => null]);
        $page = $this->actingAs(World::owner($business))->get(route('settings.rewards'));
        $page->assertOk();
        $page->assertSee('Start from a ready-made pack');
        $page->assertDontSee('owners like you');
        $page->assertDontSee('Most barbers start here');

        $onboarding = $this->actingAs(World::owner($business))->get(route('onboarding.step', 'rewards'));
        $onboarding->assertOk();
        $onboarding->assertSee('What do you want to give a new customer who comes in from a referral?');
        $onboarding->assertDontSee('owners like you');
    }

    public function test_cafe_pack_note_is_a_labeled_example(): void
    {
        $packs = \App\Support\StarterPacks::forType('cafe');
        $notes = array_column($packs, 'cost');
        $joined = implode(' ', $notes);

        $this->assertStringContainsString('Example: about $1.10 of cost for a regular coffee, which customers value at more.', $joined);
        $this->assertStringNotContainsString('Costs you about $1.10', $joined);
    }

    public function test_starter_kit_block_is_a_waitlist_and_sends_no_mail(): void
    {
        Mail::fake();
        $page = $this->get(route('home'));
        $page->assertOk();
        $page->assertSee('Join the list for the free Referral Program Starter Kit');
        $page->assertSee('we will email it when it is ready');
        $page->assertDontSee('We email you the kit');
        $page->assertDontSee('Unsubscribe any time');
    }

    public function test_footer_newsletter_line_drops_the_unsubscribe_promise(): void
    {
        $page = $this->get(route('home'));
        $page->assertOk();
        $page->assertSee('We send occasional product updates.');
        $page->assertDontSee('Unsubscribe any time');
    }

    // ------------------------------------------------ A3-3 business-local dates

    public function test_customer_dates_render_in_business_local_time(): void
    {
        $business = World::business(); // America/New_York
        $membership = $this->member($business);
        // 2026-10-28 00:30 UTC is 2026-10-27 20:30 in New York.
        $membership->forceFill(['created_at' => Carbon::parse('2026-10-28 00:30:00', 'UTC')])->save();

        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('customers.index'))
            ->assertOk()->assertSee('joined Oct 27, 2026')->assertDontSee('joined Oct 28, 2026');
        $this->actingAs($owner)->get(route('customers.show', $membership))
            ->assertOk()->assertSee('Joined Oct 27, 2026');
        $this->actingAs($owner)->get(route('customers.edit', $membership))
            ->assertOk()->assertSee('Joined Oct 27, 2026');
    }

    public function test_customer_show_consent_referral_and_visit_rows_render_local_dates(): void
    {
        $business = World::business();
        $referrer = $this->member($business);
        $friend = World::customer('+1212555'.($this->seq++), 'Fri', 'End');
        [$referral] = World::referral($referrer, $friend);
        $evening = Carbon::parse('2026-10-28 00:30:00', 'UTC'); // Oct 27 in New York
        $referral->forceFill(['created_at' => $evening])->save();

        $consentEvent = ConsentEvent::create([
            'membership_id' => $referrer->id,
            'customer_id' => $referrer->customer_id,
            'business_id' => $business->id,
            'event_type' => 'provenance_asserted',
            'source' => 'owner_asserted',
            'consent_wording_version' => '1',
            'flow' => 'owner_add',
        ]);
        $consentEvent->forceFill(['created_at' => $evening])->save();

        $page = $this->actingAs(World::owner($business))->get(route('customers.show', $referrer));
        $page->assertOk();
        $page->assertSee('Consent last changed: Oct 27, 2026');
        // The referral row carries the same evening instant.
        $page->assertSee('Oct 27, 2026');
    }

    public function test_campaign_dates_render_in_business_local_time(): void
    {
        $business = World::business();
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Evening campaign',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => World::owner($business)->id,
            'sent_at' => Carbon::parse('2026-10-28 00:30:00', 'UTC'),
            'created_at' => Carbon::parse('2026-10-28 00:30:00', 'UTC'),
        ]);

        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.index'))
            ->assertOk()->assertSee('Sent Oct 27, 2026')->assertDontSee('Sent Oct 28, 2026');
        $this->actingAs($owner)->get(route('campaigns.show', $campaign))
            ->assertOk()->assertSee('Sent Oct 27, 2026');
    }

    public function test_security_session_time_renders_in_business_local_time(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        // 2026-10-28 15:00 in New York is 19:00 UTC.
        $timestamp = Carbon::parse('2026-10-28 15:00:00', 'America/New_York')->utc()->timestamp;
        DB::table('sessions')->insert([
            'id' => 'a3-session-1',
            'user_id' => $owner->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'payload' => '',
            'last_activity' => $timestamp,
        ]);

        $page = $this->actingAs($owner)->get(route('security'));
        $page->assertOk();
        $page->assertSee('Oct 28, 3:00 PM');
        $page->assertDontSee('Oct 28, 7:00 PM');
    }

    public function test_a2_owned_date_sites_stay_business_local(): void
    {
        // Verification pins over the Train A2 sites (referrals
        // index x3, account closed line): the UTC class closes
        // once, across trains.
        $business = World::business();
        $referrer = $this->member($business);
        $friend = World::customer('+1212555'.($this->seq++), 'Fri', 'End');
        [$referral] = World::referral($referrer, $friend);
        $referral->forceFill(['created_at' => Carbon::parse('2026-10-28 00:30:00', 'UTC')])->save();

        $page = $this->actingAs(World::owner($business))->get(route('referrals.index'));
        $page->assertOk();
        $page->assertSee('started Oct 27, 2026');
        $page->assertDontSee('started Oct 28, 2026');
    }

    // ------------------------------------------------ A3-4 onboarding truth

    public function test_welcome_screen_teaches_the_register_model(): void
    {
        $business = World::business();
        $page = $this->actingAs(World::owner($business))->get(route('onboarding.welcome'));

        $page->assertOk();
        $page->assertSee('A visit like that is what you pay for, and your first 5 are free.');
        $page->assertSee('The friend gets a reward at the visit, and the customer who sent them earns one too.');
        // RE-DERIVED (activation redesign, census C-05, R1):
        // the step-2 card clause is retired; ACT-WEL-01's
        // replacement form carries the model.
        $page->assertSee('Your code works at the end. Your first 5 visits are free, and adding a card keeps your code going after that.');
        $page->assertDontSee('Add your card in step 2');
        $page->assertDontSee('That visit is what you pay for, $2');
        $page->assertDontSee('Both of them get a reward');
    }

    public function test_poster_done_panel_names_the_observable_act(): void
    {
        $business = World::business();
        ReferralEvent::record('poster_downloaded', 'owner', $business->fresh());

        $page = $this->actingAs(World::owner($business))->get(route('onboarding.step', 'poster'));
        $page->assertOk();
        $page->assertSee('✓ Poster taken.');
        $page->assertSee('You can print more copies, or another spot, any time from your QR page.');
        $page->assertDontSee('Poster printed');
        $page->assertDontSee('Your first poster is out');
    }

    public function test_test_step_shows_the_inactive_notice_and_no_waiting_line_when_flow_is_off(): void
    {
        // RE-DERIVED (activation redesign, 2026-10-30):
        // billing 'setup_incomplete' is live under the
        // allowance gate; the off state here is the reward leg.
        $business = World::business(['billing_status' => 'setup_incomplete', 'friend_reward_text' => null]);
        $page = $this->actingAs(World::owner($business))->get(route('onboarding.step', 'test'));

        $page->assertOk();
        $page->assertSee('This code is not active yet.');
        $page->assertDontSee('Waiting for your join');
    }

    public function test_test_step_shows_the_waiting_line_when_flow_is_live(): void
    {
        $business = World::business();
        $this->card($business);
        $page = $this->actingAs(World::owner($business))->get(route('onboarding.step', 'test'));

        $page->assertOk();
        $page->assertSee('Waiting for your join');
        $page->assertDontSee('This code is not active yet.');
    }

    // ------------------------------------------------ A3-5 campaign confirm

    private function draftCampaign(Business $business): SmsCampaign
    {
        $owner = World::owner($business);
        $this->actingAs($owner)->post(route('campaigns.store'), ['body' => 'Spring sale is on'])->assertRedirect();

        return SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();
    }

    public function test_confirm_all_fit_keeps_the_uncapped_strings(): void
    {
        Queue::fake();
        $business = World::business();
        $this->member($business);
        $this->member($business);
        $campaign = $this->draftCampaign($business);

        $page = $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $campaign));
        $page->assertOk();
        $page->assertSee('for 2 customers. Once sent, this text cannot be unsent.');
        $page->assertSee('Send to 2 customers');
    }

    public function test_confirm_partial_fit_scopes_line_button_and_receipt_to_fits(): void
    {
        Queue::fake();
        Config::set('campaigns.daily_cap', 3);
        $business = World::business();
        for ($i = 0; $i < 5; $i++) {
            $this->member($business);
        }
        $campaign = $this->draftCampaign($business);

        $page = $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $campaign));
        $page->assertOk();
        $page->assertSee('for 3 customers today. The other 2 will not be sent. Once sent, this text cannot be unsent.');
        $page->assertSee('Send to 3 customers');
        $page->assertSee('3 of 5 today');
        $page->assertDontSee('Send to 5 customers');
    }

    public function test_confirm_zero_fit_suppresses_the_send_form_and_keeps_the_draft(): void
    {
        Queue::fake();
        Config::set('campaigns.daily_cap', 0);
        $business = World::business();
        $this->member($business);
        $this->member($business);
        $campaign = $this->draftCampaign($business);

        $page = $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $campaign));
        $page->assertOk();
        $page->assertSee('Total $0.00 for 0 customers today.');
        $page->assertSee('This draft stays saved. You can send it tomorrow.');
        $page->assertDontSee('Send to');
        $this->assertSame(SmsCampaign::STATUS_DRAFT, $campaign->fresh()->status);
    }

    // ------------------------------------------------ A3-6 security alert emails

    public function test_security_alert_emails_claim_only_what_the_trigger_measures(): void
    {
        $unlock = new AccountUnlockNotification('token123');
        $alert = new LoginAttemptAlertNotification(25);
        $user = World::owner(World::business());

        $unlockMail = $unlock->toMail($user);
        $alertMail = $alert->toMail($user);
        $unlockText = implode(' ', array_merge($unlockMail->introLines, $unlockMail->outroLines));
        $alertText = implode(' ', array_merge($alertMail->introLines, $alertMail->outroLines));

        $this->assertStringContainsString('temporarily locked because of repeated failed sign-in attempts.', $unlockText);
        $this->assertStringContainsString('We recorded 25 failed sign-in attempts against your account in the last hour.', $alertText);
        // OB-08 (Amendment 45): the alert no longer claims in
        // the past tense that the separate unlock email was
        // already sent (it is issued a moment later and could
        // fail independently).
        $this->assertStringContainsString('Use the unlock link in the separate email about unlocking your account, or wait for the lock to pass.', $alertText);
        $this->assertStringNotContainsString('in the email we sent you', $alertText);

        foreach ([$unlockText, $alertText] as $text) {
            $this->assertStringNotContainsString('multiple locations', $text);
            $this->assertStringNotContainsString('verification step', $text);
            $this->assertStringNotContainsString('complete the verification', $text);
        }
    }

    // ------------------------------------------------ A3-7 settings lines

    public function test_rewards_preview_card_scopes_each_reward_to_its_own_timing(): void
    {
        $business = World::business();
        $page = $this->actingAs(World::owner($business))->get(route('settings.rewards'));

        $page->assertOk();
        $page->assertSee('The friend\'s reward is ready at the visit. The referrer\'s reward is ready', false);
        $page->assertDontSee('Rewards are ready');
    }

    public function test_reviews_state_line_names_the_claimed_state_not_the_ready_state(): void
    {
        $business = World::business();
        $page = $this->actingAs(World::owner($business))->get(route('settings.reviews'));

        $page->assertOk();
        $page->assertSee('Customers see this link on their receipt after a visit, and again when they claim a reward, at most once every 90 days for each customer.');
        $page->assertDontSee('when a reward is ready to claim');
    }

    // ------------------------------------------------ A3-8 print truth (chrome half)

    public function test_qr_page_warns_at_the_print_controls_while_the_flow_is_inactive(): void
    {
        // RE-DERIVED (activation redesign, census C-15,
        // ACT-QR-03): the warning's allowance form, in the
        // allowance-exhausted state (the generic form survives
        // for the other causes, beside the rewards notice).
        $business = World::business(['billing_status' => 'none']);
        $qrReferrer = World::customer('+12125551301', 'Reg', 'Ular');
        [$qrMembership] = World::membership($business, $qrReferrer);
        for ($i = 0; $i < 5; $i++) {
            $qrFriend = World::customer('+1212555131'.(string) $i, 'Fri', 'End');
            [$qrReferral] = World::referral($qrMembership, $qrFriend);
            \App\Models\BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $qrReferral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }
        $page = $this->actingAs(World::owner($business->fresh()))->get(route('owner.qr'));

        $page->assertOk();
        $page->assertSee('Your code is paused right now. You can print, but anyone who scans this poster will see a paused page until you add a card.');
    }

    public function test_qr_page_has_no_print_warning_and_shows_the_staleness_line_when_live(): void
    {
        $business = World::business();
        $this->card($business);
        $page = $this->actingAs(World::owner($business))->get(route('owner.qr'));

        $page->assertOk();
        $page->assertDontSee('Your program is not on right now.');
        $page->assertSee('Printed posters keep the words they were printed with. If you change your rewards, print fresh posters: the old ones keep the old words.');
    }

    public function test_rewards_save_reminds_about_printed_posters_only_when_a_text_changed_and_a_download_exists(): void
    {
        $business = World::business();
        ReferralEvent::record('poster_downloaded', 'owner', $business->fresh());
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->post(route('settings.rewards.update'), [
            'friend_reward_text' => 'A free coffee on your first visit',
            'referrer_reward_text' => '$10 off',
            'redemption_window_days' => 30,
            'reward_vesting_days' => 14,
        ]);
        $response->assertSessionHas('status', 'Rewards saved. Your printed posters still show the old rewards. Print fresh posters from your QR page.');

        // Same save values again: no text changed, no reminder.
        $response = $this->actingAs($owner)->post(route('settings.rewards.update'), [
            'friend_reward_text' => 'A free coffee on your first visit',
            'referrer_reward_text' => '$10 off',
            'redemption_window_days' => 30,
            'reward_vesting_days' => 14,
        ]);
        $response->assertSessionHas('status', 'Rewards saved.');
    }

    public function test_rewards_save_without_a_download_has_no_poster_reminder(): void
    {
        $business = World::business();
        $response = $this->actingAs(World::owner($business))->post(route('settings.rewards.update'), [
            'friend_reward_text' => 'A free coffee on your first visit',
            'referrer_reward_text' => '$10 off',
            'redemption_window_days' => 30,
            'reward_vesting_days' => 14,
        ]);
        $response->assertSessionHas('status', 'Rewards saved.');
    }

    // ------------------------------------------------ A3-9 flash + real time

    public function test_owner_asserted_add_flash_names_the_confirmation_step(): void
    {
        Queue::fake();
        $business = World::business();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->post(route('customers.store'), [
            'first_name' => 'Ana',
            'last_name' => 'Ray',
            'phone' => '2125550991',
            'consent' => '1',
        ]);

        $response->assertSessionHas('status', 'Customer added. A confirmation text is on its way; their referral link follows when they reply YES.');
    }

    public function test_real_time_claims_are_gone_from_faq_and_check_rows(): void
    {
        $home = $this->get(route('home'));
        $home->assertOk();
        $home->assertSee('Every visit, counted and listed');
        $home->assertDontSee('Real-time analytics');

        $faq = $this->get(route('faq'));
        $faq->assertOk();
        $faq->assertSee('The owner dashboard shows your referrals, rewards, customers, and campaign results.');
        $faq->assertDontSee('in real time');
    }

    // ------------------------------------------------ Persona F-2: remove-card modal branches by billing state

    public function test_remove_card_modal_states_the_counting_truth_while_billing_is_active(): void
    {
        $business = World::business();
        $this->card($business);
        $page = $this->actingAs(World::owner($business))->get(route('settings.billing'));

        $page->assertOk();
        // RE-DERIVED (activation redesign, R9, ACT-BILL-08B).
        $page->assertSee('Remove this card? Visits keep counting while your free visits last (5 left). Charges pause until you add a card, and the amount you owe stays on your account.');
    }

    public function test_remove_card_modal_states_the_paused_truth_on_a_paused_past_due_page(): void
    {
        Carbon::setTestNow('2026-03-10 02:00:00');
        $business = World::business(['billing_status' => 'past_due']);
        AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_pay_failed'], $business->fresh(), null);
        Carbon::setTestNow('2026-03-20 12:00:00');

        $this->card($business->fresh());
        $page = $this->actingAs(World::owner($business->fresh()))->get(route('settings.billing'));

        $page->assertOk();
        $page->assertSee('Remove this card? Your QR code and customer links are paused until you update your card. Visits are not counting and rewards are not being earned while your program is paused. Charges resume when you add a card, and the amount you owe stays on your account.');
        $page->assertDontSee('Visits keep counting and rewards keep working');
    }

    public function test_remove_card_modal_states_the_off_truth_when_the_business_is_not_active(): void
    {
        $business = World::business(['status' => 'disabled']);
        $this->card($business);
        $page = $this->actingAs(World::owner($business))->get(route('settings.billing'));

        $page->assertOk();
        $page->assertSee('Remove this card? Your QR code and customer links are off. Visits are not counting and rewards are not being earned while your program is off.');
        $page->assertDontSee('Visits keep counting and rewards keep working');
    }

    // ------------------------------------------------ Two-key note 2: close-screen held count

    public function test_close_screen_counts_a_held_referral_with_no_held_reward(): void
    {
        $business = World::business();
        $referrer = $this->member($business);
        $friend = World::customer('+1212555'.($this->seq++), 'Fri', 'End');
        World::referral($referrer, $friend, ['hold' => true, 'held_at' => now()]);

        $page = $this->actingAs(World::owner($business))->get(route('settings.account'));
        $page->assertOk();
        $page->assertSee('You have 1 item waiting for your review');
    }

    public function test_close_screen_counts_held_referrals_and_rewards_together(): void
    {
        $business = World::business();
        $referrer = $this->member($business);
        $friend = World::customer('+1212555'.($this->seq++), 'Fri', 'End');
        [$referral] = World::referral($referrer, $friend, ['hold' => true, 'held_at' => now()]);
        World::reward($referrer, $referral, ['hold' => true, 'held_at' => now(), 'state' => \App\Models\Reward::STATE_PENDING_VESTING]);

        $page = $this->actingAs(World::owner($business))->get(route('settings.account'));
        $page->assertOk();
        $page->assertSee('You have 2 items waiting for your review');
    }
}
