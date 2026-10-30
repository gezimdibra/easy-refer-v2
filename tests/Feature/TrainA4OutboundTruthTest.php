<?php

namespace Tests\Feature;

use App\Contracts\MarketingSmsGateway;
use App\Contracts\SmsGateway;
use App\Jobs\SendFriendLinkSms;
use App\Jobs\SendShareLinkSms;
use App\Mail\DunningPauseWarningMail;
use App\Mail\DunningPaymentFailedMail;
use App\Mail\DunningReminderMail;
use App\Mail\WelcomeMail;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\SmsMessage;
use App\Models\User;
use App\Notifications\LoginAttemptAlertNotification;
use App\Notifications\ResetPasswordNotification;
use App\Support\ReferralTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Train A4 (Amendment 45): the outbound truth sweep's residuals
 * OB-02..OB-08, the BILL-08 re-key, F2 campaign flow-state truth,
 * and N-5 (the referral timeline's bare "Update" label). Every
 * pin asserts the message RENDERS truthfully in the state that
 * used to lie, not merely that a branch exists.
 */
class TrainA4OutboundTruthTest extends TestCase
{
    use RefreshDatabase;

    private function renderMailText(Mailable $mail): string
    {
        $content = $mail->content();

        return trim((string) preg_replace('/\s+/', ' ', strip_tags(view($content->view, $content->with)->render())));
    }

    private function cardOnFile(Business $business): void
    {
        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_a4_'.uniqid(),
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
        ]);
    }

    private function dunningMails(Business $business): array
    {
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 4200,
            'currency' => 'USD',
            'idempotency_key' => 'a4_'.uniqid(),
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

    // ---------------------------------------------------- OB-02

    public function test_ob02_cardless_dunning_tail_never_promises_a_card_retry(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00');
        // Paused past-due (grace end passed is not needed: the
        // tail renders in the off/paused forms; use closed for
        // the off form and disabled for the other off form).
        foreach (['closed', 'disabled'] as $status) {
            $business = World::business(['billing_status' => 'past_due', 'status' => $status, 'closed_at' => $status === 'closed' ? now() : null]);
            foreach ($this->dunningMails($business) as $mail) {
                $text = $this->renderMailText($mail);
                $this->assertStringNotContainsString('We will try the card on file again', $text, $status.' '.class_basename($mail));
                $this->assertStringContainsString('This amount is still due.', $text, $status);
            }
        }
        Carbon::setTestNow(null);
    }

    public function test_ob02_cardless_active_business_is_told_to_add_a_card(): void
    {
        Carbon::setTestNow('2026-03-20 12:00:00'); // past the grace end: paused form
        $business = World::business(['billing_status' => 'past_due']);
        foreach ($this->dunningMails($business) as $mail) {
            $text = $this->renderMailText($mail);
            $this->assertStringContainsString('This amount is still due. Add a card so we can collect it.', $text, class_basename($mail));
            $this->assertStringNotContainsString('card on file again', $text);
        }
        Carbon::setTestNow(null);
    }

    public function test_ob02_card_on_file_tail_is_dated_with_the_next_charge(): void
    {
        Carbon::setTestNow('2026-03-20 12:00:00');
        $business = World::business(['billing_status' => 'past_due']);
        $this->cardOnFile($business);
        foreach ($this->dunningMails($business) as $mail) {
            $text = $this->renderMailText($mail);
            // Next charge run: Apr 1, 2026 03:00 UTC, which
            // the business reads on its own wall clock as
            // Mar 31 (BillingForecast::nextChargeDateFor's
            // convention, the same one the forecast mails use).
            $this->assertStringContainsString('This amount is still due. We will try the card on file again on Mar 31, 2026.', $text, class_basename($mail));
        }
        Carbon::setTestNow(null);
    }

    public function test_ob02_closed_cardless_tail_is_the_bare_still_due_sentence(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00');
        $business = World::business(['billing_status' => 'past_due', 'status' => 'closed', 'closed_at' => now()]);
        foreach ($this->dunningMails($business) as $mail) {
            $text = $this->renderMailText($mail);
            $this->assertStringContainsString('This amount is still due.', $text);
            $this->assertStringNotContainsString('Add a card so we can collect it', $text, 'closed owners have no card action');
            $this->assertStringNotContainsString('Update your card', $text);
        }
        Carbon::setTestNow(null);
    }

    // ---------------------------------------------------- OB-03

    public function test_ob03_textless_past_due_business_never_gets_the_keeps_working_form(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00'); // inside grace
        $business = World::business(['billing_status' => 'past_due', 'friend_reward_text' => null]);
        foreach ($this->dunningMails($business) as $mail) {
            $text = $this->renderMailText($mail);
            $this->assertStringNotContainsString('keeps working', $text, class_basename($mail));
            $this->assertStringNotContainsString('Keeps working', $text, class_basename($mail));
            $this->assertStringContainsString('This code is not active yet. Set your friend reward to turn it on.', $text, class_basename($mail));
            $this->assertStringContainsString('Your QR code Off', $text, class_basename($mail));
        }
        // The dated subjects must not render for the textless form either.
        $business2 = World::business(['billing_status' => 'past_due', 'friend_reward_text' => '  ']);
        $mails = $this->dunningMails($business2);
        $this->assertSame('Reminder: your Easy Refer payment is still due', $mails[1]->envelope()->subject);
        $this->assertSame('Your Easy Refer payment is still due', $mails[2]->envelope()->subject);
        Carbon::setTestNow(null);
    }

    public function test_ob03_textless_and_paused_still_renders_the_text_reason(): void
    {
        Carbon::setTestNow('2026-03-20 12:00:00'); // past grace end
        $business = World::business(['billing_status' => 'past_due', 'friend_reward_text' => null]);
        foreach ($this->dunningMails($business) as $mail) {
            $text = $this->renderMailText($mail);
            // TS3-05 re-derivation (Train A5, Amendment 52):
            // past grace the text reason still renders (never
            // the paused form), but its sufficiency claim is
            // now true: both steps are named, and the shipped
            // single-step sentence is absent in this state.
            $this->assertStringContainsString('Set your friend reward and update your card to turn it on.', $text, class_basename($mail));
            $this->assertStringNotContainsString('Set your friend reward to turn it on.', $text, class_basename($mail));
            $this->assertStringNotContainsString('paused until you update your card', $text, class_basename($mail));
        }
        Carbon::setTestNow(null);
    }

    public function test_ob03_business_with_text_keeps_the_in_grace_form(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00');
        $business = World::business(['billing_status' => 'past_due']);
        $text = $this->renderMailText($this->dunningMails($business)[0]);
        $this->assertStringContainsString('Your QR code keeps working until Mar 17, 2026.', $text);
        Carbon::setTestNow(null);
    }

    // ---------------------------------------------------- OB-04

    public function test_ob04_welcome_subject_is_computed_at_render_from_the_same_facts_as_the_body(): void
    {
        // CHANGED PIN (TS-PM-01, truth sweep 2026-10-29 PM):
        // unverified factory state. TS3-06's re-derivation
        // counted three (verify, card, friend reward text);
        // verification gates nothing, so the activation count
        // is the two real legs (card, friend reward text) and
        // the subject counts exactly those.
        $unverified = User::factory()->unverified()->create();
        // RE-DERIVED (activation redesign, census C-28): the
        // counted blockers are the reward leg, plus the card
        // only at exhaustion — the factory state (no reward,
        // no card, allowance full) counts exactly one.
        $this->assertSame(
            'Welcome to Easy Refer: one step left before your code works',
            (new WelcomeMail($unverified))->envelope()->subject
        );

        // Verified + card + friend text at render time: the body
        // says the code is ready, so the subject must not claim
        // a step is left.
        $user = User::factory()->create();
        $user->business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        $this->cardOnFile($user->business);
        $mail = new WelcomeMail($user->fresh());
        $this->assertSame('Welcome to Easy Refer', $mail->envelope()->subject);
        $this->assertStringContainsString('your code is ready to go out', $mail->render());

        // Verified, no card, factory state: the one counted
        // step is the reward (the card counts only once the
        // allowance is spent), so the one-step form renders.
        $user2 = User::factory()->create();
        $this->assertSame(
            'Welcome to Easy Refer: one step left before your code works',
            (new WelcomeMail($user2->fresh()))->envelope()->subject
        );
    }

    // ---------------------------------------------------- OB-05

    public function test_ob05_share_link_sms_drops_the_earning_sentence_while_the_flow_is_inactive(): void
    {
        $gateway = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $gateway);

        $business = World::business(['friend_reward_text' => null]); // flow inactive: no card either
        [$membership, $plain] = World::membership($business, World::customer('+12125550101'));
        $membership->forceFill(['share_token_state' => 'active'])->save();

        (new SendShareLinkSms($membership->id))->handle($gateway);

        $this->assertCount(1, $gateway->sent);
        $this->assertStringContainsString('Here is your referral link:', $gateway->sent[0]['body']);
        $this->assertStringNotContainsString('you earn your reward', $gateway->sent[0]['body']);
    }

    public function test_ob05_share_link_sms_keeps_the_earning_sentence_while_the_flow_is_active(): void
    {
        $gateway = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $gateway);

        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550102'));

        (new SendShareLinkSms($membership->id))->handle($gateway);

        $this->assertCount(1, $gateway->sent);
        $this->assertStringContainsString('when they visit, you earn your reward', $gateway->sent[0]['body']);
    }

    // ---------------------------------------------------- OB-06

    public function test_ob06_friend_link_sms_is_skipped_unless_the_referral_is_still_pending(): void
    {
        $gateway = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $gateway);

        $business = World::business();
        [$referrer] = World::membership($business, World::customer('+12125550111'));
        $friend = World::customer('+12125550112', 'Flo', 'Friend');

        // A fresh friend per state: BREAKER D-1's counted-pair
        // key admits only one live-or-counted referral per
        // (business, friend), so one friend can no longer carry
        // a redeemed AND a voided row at once.
        foreach ([Referral::STATE_REDEEMED, Referral::STATE_VOIDED, Referral::STATE_EXPIRED] as $i => $state) {
            $stateFriend = $i === 0 ? $friend : World::customer('+1212555011'.(6 + $i), 'Flo', 'Friend');
            [$referral] = World::referral($referrer, $stateFriend, ['state' => $state]);
            (new SendFriendLinkSms($referral->id))->handle($gateway);
        }
        $this->assertCount(0, $gateway->sent, 'no friend-link text for a non-pending referral');
        $this->assertSame(0, SmsMessage::query()->where('purpose', 'friend_link')->count());

        [$pending] = World::referral($referrer, World::customer('+12125550113', 'Pat', 'Pending'));
        (new SendFriendLinkSms($pending->id))->handle($gateway);
        $this->assertCount(1, $gateway->sent);
        $this->assertStringContainsString('Claim your reward:', $gateway->sent[0]['body']);
    }

    public function test_ob06_friend_link_sms_is_skipped_for_a_held_referral(): void
    {
        $gateway = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $gateway);

        $business = World::business();
        [$referrer] = World::membership($business, World::customer('+12125550114'));
        [$referral] = World::referral($referrer, World::customer('+12125550115'), ['hold' => true]);

        (new SendFriendLinkSms($referral->id))->handle($gateway);

        $this->assertCount(0, $gateway->sent);
    }

    // ---------------------------------------------------- OB-07

    public function test_ob07_reset_mail_expiry_is_anchored_to_the_tokens_creation(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00');
        $user = User::factory()->create();
        // The broker created this token 45 minutes ago; the
        // queued notification is only processed now.
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => hash('sha256', 'a4-token'),
            'created_at' => now()->subMinutes(45),
        ]);

        $mail = (new ResetPasswordNotification('a4-token'))->toMail($user);
        $text = implode(' ', array_merge($mail->introLines, $mail->outroLines));

        $this->assertStringNotContainsString('will expire in 60 minutes', $text);
        // Two-key Condition 2 (2026-10-28): the anchored expiry
        // renders in the recipient business's local time, not
        // bare UTC. Created 11:15 UTC + 60m = 12:15 PM UTC,
        // which the factory business (America/New_York, EDT)
        // reads as 8:15 AM on its own wall clock.
        $this->assertStringContainsString('will expire at 8:15 AM', $text);
        $this->assertStringNotContainsString('will expire at 12:15 PM', $text);
        Carbon::setTestNow(null);
    }

    public function test_ob07_reset_mail_without_a_token_row_softens_the_expiry_claim(): void
    {
        $user = User::factory()->create();
        $mail = (new ResetPasswordNotification('orphan-token'))->toMail($user);
        $text = implode(' ', array_merge($mail->introLines, $mail->outroLines));
        $this->assertStringNotContainsString('will expire in 60 minutes', $text);
        $this->assertStringContainsString('expires within the hour', $text);
    }

    // ---------------------------------------------------- OB-08

    public function test_ob08_login_alert_no_longer_claims_the_unlock_email_was_already_sent(): void
    {
        $user = World::owner(World::business());
        $mail = (new LoginAttemptAlertNotification(25))->toMail($user);
        $text = implode(' ', array_merge($mail->introLines, $mail->outroLines));

        $this->assertStringNotContainsString('in the email we sent you', $text);
        $this->assertStringContainsString('Use the unlock link in the separate email about unlocking your account, or wait for the lock to pass.', $text);
    }

    // ------------------------------------------------- BILL-08

    public function test_bill08_textless_business_is_not_told_visits_keep_counting_in_the_remove_card_modal(): void
    {
        $business = World::business(['friend_reward_text' => null]);
        $this->cardOnFile($business);

        $page = $this->actingAs(World::owner($business))->get(route('settings.billing'));
        $page->assertOk();
        $page->assertSee('Remove this card? Your QR code is not active yet, so visits are not counting and rewards are not being earned. Charges pause until you add a card, and the amount you owe stays on your account.', false);
        $page->assertDontSee('Visits keep counting and rewards keep working.', false);
    }

    public function test_bill08_active_business_with_text_keeps_the_keeps_counting_form(): void
    {
        $business = World::business();
        $this->cardOnFile($business);

        $page = $this->actingAs(World::owner($business))->get(route('settings.billing'));
        $page->assertOk();
        // RE-DERIVED (activation redesign, R9, ACT-BILL-08B):
        // the keeps-counting form carries the allowance count.
        $page->assertSee('Visits keep counting while your free visits last (5 left).', false);
    }

    // ------------------------------------------------------- F2

    public function test_f2_closed_business_is_blocked_from_sending_and_told_why(): void
    {
        Queue::fake();
        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
        $business = World::business(['status' => 'closed', 'closed_at' => now()]);
        [$m] = World::membership($business, World::customer('+12125550121'));
        $m->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();
        $owner = World::owner($business);

        // Compose names the block.
        $create = $this->actingAs($owner)->get(route('campaigns.create'));
        $create->assertOk();
        $create->assertSee('Your program has ended, so text campaigns cannot be sent.', false);

        // A draft taken to confirm names the block and offers no send.
        $campaign = \App\Models\SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'We are back',
            'status' => \App\Models\SmsCampaign::STATUS_DRAFT, 'created_by' => $owner->id,
        ]);
        $confirm = $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));
        $confirm->assertOk();
        $confirm->assertSee('Your program has ended, so this text cannot be sent.', false);
        $confirm->assertDontSee('Send to 1 customers', false);

        // The send itself refuses. (At HTTP level the closed
        // middleware already intercepts every write for a
        // closed business; the sender-level block is pinned
        // directly so the refusal does not depend on that
        // middleware's route list.)
        $result = app(\App\Services\Campaigns\CampaignSender::class)->send($campaign);
        $this->assertSame('blocked_closed', $result['outcome']);
        $this->actingAs($owner)->post(route('campaigns.send', $campaign))->assertRedirect();
        $this->assertSame(\App\Models\SmsCampaign::STATUS_DRAFT, $campaign->fresh()->status);
        $this->assertSame(0, \App\Models\SmsCampaignRecipient::query()->count());
    }

    public function test_f2_inactive_flow_business_gets_the_named_warning_but_can_still_send(): void
    {
        Queue::fake();
        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);

        foreach (['textless', 'paused'] as $kind) {
            $business = $kind === 'textless'
                ? World::business(['friend_reward_text' => null])
                : World::business(['billing_status' => 'past_due']);
            if ($kind === 'paused') {
                // Past grace: the episode audit row is written
                // 10 days in the past (audit rows are immutable,
                // so the write itself travels, not the row).
                Carbon::setTestNow(now()->subDays(10));
                \App\Models\AuditLog::write('billing.past_due', null, [], $business, null);
                Carbon::setTestNow(null);
                $business = $business->fresh();
            }
            [$m] = World::membership($business, World::customer('+12125550122'));
            $m->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();
            $owner = World::owner($business);
            // Two-key Condition 1 (2026-10-28): the warning is
            // branched by cause. Textless keeps the strong form
            // (friends from these texts are stopped at the flow
            // gate and hold nothing to redeem). Paused renders
            // the scoped form: the claim covers NEW arrivals
            // only, the false "or redeem" clause is absent, and
            // the already-earned truth is stated, because
            // claimReward / redeemReferral carry no pause gate.
            [$warning, $forbidden] = $kind === 'textless'
                ? [
                    'Your program is not active right now. Friends who come from these texts cannot earn or redeem rewards, and their visits will not count.',
                    'Rewards already earned can still be redeemed.',
                ]
                : [
                    'Your program is not active right now. Friends who come from these texts cannot earn rewards, and their visits will not count. Rewards already earned can still be redeemed.',
                    'cannot earn or redeem rewards',
                ];

            $create = $this->actingAs($owner)->get(route('campaigns.create'));
            $create->assertSee($warning, false);
            $create->assertDontSee($forbidden, false);

            $campaign = \App\Models\SmsCampaign::create([
                'business_id' => $business->id, 'body' => 'Come back in',
                'status' => \App\Models\SmsCampaign::STATUS_DRAFT, 'created_by' => $owner->id,
            ]);
            $confirm = $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));
            $confirm->assertSee($warning, false);
            $confirm->assertDontSee($forbidden, false);
            $confirm->assertSee('Send to 1 customer', false);

            // The send still goes through.
            $this->actingAs($owner)->post(route('campaigns.send', $campaign))->assertRedirect();
            $this->assertSame(\App\Models\SmsCampaign::STATUS_SENDING, $campaign->fresh()->status);
        }
    }

    public function test_f2_active_business_sees_no_flow_warning(): void
    {
        Queue::fake();
        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
        $business = World::business();
        [$m] = World::membership($business, World::customer('+12125550123'));
        $m->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('campaigns.create'))
            ->assertDontSee('Your program is not active right now.', false)
            ->assertDontSee('Your program has ended', false);
        $campaign = \App\Models\SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Hello',
            'status' => \App\Models\SmsCampaign::STATUS_DRAFT, 'created_by' => $owner->id,
        ]);
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))
            ->assertDontSee('Your program is not active right now.', false)
            ->assertDontSee('Your program has ended', false);
    }

    // ------------------------------------------------------- N-5

    public function test_n5_every_referral_scoped_event_type_has_a_truthful_timeline_label(): void
    {
        $expected = [
            'clawback' => 'Reward clawed back',
            'redemption_blocked_missing_reward_text' => 'Redemption blocked: no reward was set',
            'reward_minted' => 'Referrer reward earned',
            'reward_claimed' => 'Reward claimed',
            'risk_signal' => 'Risk signal recorded',
        ];
        foreach ($expected as $type => $label) {
            $this->assertSame($label, ReferralTimeline::label($type), $type);
        }

        // And the detail page renders the name, never bare "Update".
        $business = World::business();
        [$referrer] = World::membership($business, World::customer('+12125550131'));
        [$referral] = World::referral($referrer, World::customer('+12125550132'));
        ReferralEvent::record('reward_minted', 'system', $business, $referral, null, $referrer, []);
        ReferralEvent::record('risk_signal', 'system', $business, $referral, null, $referrer, []);

        $page = $this->actingAs(World::owner($business))->get(route('referrals.show', $referral));
        $page->assertOk();
        $page->assertSee('Referrer reward earned', false);
        $page->assertSee('Risk signal recorded', false);
        $page->assertDontSee('>Update<', false);
    }
}
