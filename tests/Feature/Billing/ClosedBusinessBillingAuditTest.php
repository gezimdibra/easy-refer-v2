<?php

namespace Tests\Feature\Billing;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Mail\DunningPauseWarningMail;
use App\Mail\DunningPaymentFailedMail;
use App\Mail\DunningReminderMail;
use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\BusinessAlert;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use App\Services\Auth\StepUpService;
use App\Services\Billing\SquareWebhookHandler;
use App\Services\Referral\RedemptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * CB AUDIT (PM review 2026-10-27, item B1): what actually happens
 * to a CLOSED business's money. closeAccount() says billing state
 * is "left for the final reconciliation" and the Billing page
 * tells owners "To stop billing entirely, close your account."
 * These tests pin the executable truth, section by section:
 *
 *  (a) no NEW billable event can accrue after closure
 *      (enrollment, friend claim, visit counting, campaign send);
 *  (b) billing:charge on a closed business with owed months:
 *      whether it drains, stops, retries, or strands;
 *  (c) dunning on a closed business;
 *  (d) free-visit credits and the cap on the final drain.
 *
 * Closure itself always goes through the real HTTP endpoint
 * (step-up session), never a hand-set status column.
 */
class ClosedBusinessBillingAuditTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private FakePhoneVerificationGateway $verify;

    private int $friendSequence = 7000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        $this->verify = new FakePhoneVerificationGateway;
        $this->app->instance(PhoneVerificationGateway::class, $this->verify);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(\App\Contracts\MarketingSmsGateway::class, new \Tests\Fakes\FakeMarketingSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
        config(['billing.charges_enabled' => true]);
    }

    // ---------------------------------------------------------------
    // Fixture helpers (shapes copied from MonthlyChargerTest,
    // MonthlyChargerCampaignLegTest, DunningTest, ClosureTest).
    // ---------------------------------------------------------------

    private function closeBusiness(Business $business): void
    {
        $this->actingAs(World::owner($business))
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.account.close'), ['confirm' => '1'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame('closed', $business->fresh()->status);
    }

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550988', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    private function event(Business $business, Membership $membership, int $amountCents = 200, ?Carbon $at = null): BillingEvent
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED]);

        $event = BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => $amountCents,
        ]);

        if ($at !== null) {
            $event->forceFill(['created_at' => $at])->save();
        }

        return $event;
    }

    private function cardOnFile(Business $business): PaymentMethod
    {
        return PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'sq_card_test',
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_test',
        ]);
    }

    /** Attempted campaign recipient rows (evidence-linked, hence billable). */
    private function attemptedRecipients(Business $business, array $amounts, ?Carbon $at = null): array
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

        $rows = [];
        $seq = 7800;
        foreach ($amounts as $i => $amount) {
            $customer = World::customer('+1212555'.($seq++), 'Cam', 'Paign');
            [$membership] = World::membership($business, $customer);

            $message = SmsMessage::create([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'phone_e164' => $customer->phone_e164,
                'purpose' => 'campaign',
                'messaging_service' => 'marketing',
                'template_key' => 'campaign',
                'rendered_body' => 'Spring sale is on',
                'twilio_message_sid' => 'SM_CB_'.$campaign->id.'_'.$i,
                'delivery_state' => 'sent',
                'consent_basis' => 'customer_checkbox',
                'opt_out_state_at_send' => false,
            ]);

            $row = SmsCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'membership_id' => $membership->id,
                'customer_id' => $customer->id,
                'phone_e164' => $customer->phone_e164,
                'twilio_message_sid' => 'SM_CB_'.$campaign->id.'_'.$i,
                'sms_message_id' => $message->id,
                'delivery_state' => 'sent',
                'segments' => 1,
                'amount_cents' => $amount,
            ]);

            if ($at !== null) {
                $row->forceFill(['created_at' => $at])->save();
                $message->forceFill(['created_at' => $at])->save();
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function runCharge(): void
    {
        $this->artisan('billing:charge')->assertExitCode(0);
    }

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    // ---------------------------------------------------------------
    // (a) No new billable accrual after closure.
    // ---------------------------------------------------------------

    public function test_a_public_flow_starts_no_enrollment_or_friend_claim_after_closure(): void
    {
        $business = World::business();
        [, $qrPlain] = World::qr($business);
        [$membership, $sharePlain] = World::membership($business, World::customer('+12125550411', 'Ana', 'Ray'));

        $this->closeBusiness($business);

        // The QR landing and the friend landing both answer with
        // the generic not-active page (flowIsLive requires an
        // active business).
        $this->get(route('refer.landing', ['token' => $qrPlain]))->assertNotFound();
        $this->get(route('refer.friend', ['token' => $sharePlain]))->assertNotFound();

        // The enrollment POST never reaches verification.
        $this->post(route('refer.enroll', ['token' => $qrPlain]), [
            'first_name' => 'New', 'last_name' => 'Person',
            'phone' => '+12125550701', 'consent' => '1',
        ])->assertNotFound();

        // The friend-claim POST never reaches verification either:
        // no code is started, so no referral can be completed.
        $this->post(route('refer.friend.claim', ['token' => $sharePlain]), [
            'first_name' => 'New', 'last_name' => 'Friend',
            'phone' => '+12125550702', 'consent' => '1',
        ])->assertNotFound();

        $this->assertSame([], $this->verify->starts);
        $this->assertSame(0, Referral::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, BillingEvent::query()->where('business_id', $business->id)->count());
    }

    public function test_a_pending_referral_voided_at_closure_cannot_be_redeemed_into_a_billing_event(): void
    {
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550421', 'Ana', 'Ray'));
        [$pending, $redeemPlain] = World::referral($membership, World::customer('+12125550422', 'Fay', 'One'));

        $this->closeBusiness($business);
        $this->assertSame(Referral::STATE_VOIDED, $pending->fresh()->state);

        // The staff screen answers generically for the voided token.
        $this->get(route('redeem.show', ['token' => $redeemPlain]))->assertNotFound();

        // And the redemption service itself refuses the transition:
        // no state change, no billing event, no reward minted.
        $result = app(RedemptionService::class)->redeemReferral($pending->fresh(), null, null);

        $this->assertSame('voided', $result['outcome']);
        $this->assertSame(0, BillingEvent::query()->where('business_id', $business->id)->count());
    }

    public function test_a_campaign_send_is_refused_after_closure_and_creates_no_recipients(): void
    {
        $business = World::business();
        $member = World::customer('+12125550431', 'Mem', 'Ber');
        [$membership] = World::membership($business, $member);
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        $owner = World::owner($business);
        $this->actingAs($owner)->post(route('campaigns.store'), ['body' => 'Spring sale is on'])->assertRedirect();
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();
        // Stamp the figures the way the confirm screen does, so the
        // send POST would succeed if it ever reached the controller.
        $this->actingAs($owner)->get(route('campaigns.confirm', ['campaign' => $campaign->id]))->assertOk();

        $this->closeBusiness($business);

        Queue::fake();
        // A freshly loaded owner: the pre-closure $owner instance
        // carries its business relation cached as active, which
        // would mask the middleware (each real request loads the
        // business fresh, as this does).
        $this->actingAs(World::owner($business))->post(route('campaigns.send', ['campaign' => $campaign->id]))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(SmsCampaign::STATUS_DRAFT, $campaign->fresh()->status);
        $this->assertSame(0, SmsCampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_a_inflight_friend_claim_verified_after_closure_accrues_nothing(): void
    {
        // The race the entry gates cannot see: the friend starts
        // verification while the business is active (the code is
        // already on their phone), the owner closes the account,
        // and only then does the friend submit the code. The
        // completion step must re-check liveness: a referral
        // completed after closure was never voided by
        // closeAccount (it did not exist yet) and its redemption
        // would mint a brand-new billing event for a business the
        // owner was told had stopped billing.
        $business = World::business();
        [$membership, $sharePlain] = World::membership($business, World::customer('+12125550441', 'Ana', 'Ray'));

        $this->carryFlowCookie($this->get(route('refer.friend', ['token' => $sharePlain]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $sharePlain]), [
            'first_name' => 'Fay', 'last_name' => 'Friend',
            'phone' => '+12125550442', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->assertCount(1, $this->verify->starts);

        $this->closeBusiness($business);

        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertNotFound();

        $this->assertSame(0, Referral::query()->where('business_id', $business->id)->count(),
            'a friend claim verified after closure created a referral');
        $this->assertSame(0, BillingEvent::query()->where('business_id', $business->id)->count());
    }

    public function test_a_inflight_enrollment_verified_after_closure_creates_no_membership(): void
    {
        // The enroll twin of the claim race above: the code was
        // requested while the business was active; the account
        // closes before the customer submits it. Completion must
        // not create the membership (the first link in every
        // later billable chain) on a closed business.
        $business = World::business();
        [, $qrPlain] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrPlain]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrPlain]), [
            'first_name' => 'Pat', 'last_name' => 'Lee',
            'phone' => '+12125550451', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->assertCount(1, $this->verify->starts);

        $this->closeBusiness($business);

        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertNotFound();

        $this->assertSame(0, \App\Models\Membership::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, BillingEvent::query()->where('business_id', $business->id)->count());
    }

    // ---------------------------------------------------------------
    // (b) billing:charge on a closed business.
    // ---------------------------------------------------------------

    public function test_b_owed_months_drain_once_per_month_in_one_run_and_then_stop_forever(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        $this->event($business, $membership, 200, Carbon::parse('2026-01-10 12:00:00'));
        $this->event($business, $membership, 200, Carbon::parse('2026-01-11 12:00:00'));
        $this->event($business, $membership, 200, Carbon::parse('2026-02-10 12:00:00'));
        $this->event($business, $membership, 200, Carbon::parse('2026-02-11 12:00:00'));
        $this->event($business, $membership, 200, Carbon::parse('2026-02-12 12:00:00'));

        $this->closeBusiness($business);

        // One monthly run drains BOTH owed months, oldest first,
        // as one record per month (the arrears rule).
        $this->runCharge();

        $records = BillingRecord::query()->where('business_id', $business->id)->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertSame([400, 600], $records->pluck('amount_cents')->all());
        $this->assertSame(
            [BillingRecord::STATE_SUCCEEDED, BillingRecord::STATE_SUCCEEDED],
            $records->pluck('state')->all(),
        );
        $this->assertCount(2, $this->gateway->chargeCalls);
        $this->assertSame(0, BillingEvent::query()->where('business_id', $business->id)->whereNull('billing_record_id')->count());

        // Later runs are no-ops forever: nothing unclaimed remains,
        // so no new record and no new gateway call can appear.
        $this->runCharge();
        $this->runCharge();

        $this->assertSame(2, BillingRecord::query()->where('business_id', $business->id)->count());
        $this->assertCount(2, $this->gateway->chargeCalls);
    }

    public function test_b_closed_business_without_a_card_is_never_charged_and_alerts_once(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $event = $this->event($business, $membership);

        $this->closeBusiness($business);

        $this->runCharge();
        $this->runCharge();
        $this->runCharge();

        // No record, no gateway call: the debt is NOT collected.
        // It stays visible as an unclaimed billable event (never
        // silently written off) and the missing-card alert is
        // raised exactly once (firstOrCreate), not per run.
        $this->assertSame(0, BillingRecord::query()->where('business_id', $business->id)->count());
        $this->assertCount(0, $this->gateway->chargeCalls);
        $this->assertNull($event->fresh()->billing_record_id);
        $this->assertSame('billable', $event->fresh()->coverage);
        $this->assertSame(1, BusinessAlert::query()
            ->where('business_id', $business->id)
            ->where('kind', 'billing_card_missing')
            ->count());
    }

    public function test_b_failed_card_is_attempted_once_per_run_and_the_owner_can_replace_it_while_owed(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        $event = $this->event($business, $membership);
        $this->gateway->chargeFailureCode = 'CARD_DECLINED';

        $this->closeBusiness($business);

        // FE-CANCEL-01: the tap itself attempts the final bill
        // (one attempt against the card; the failure stops that
        // drain). Across monthly runs there is no terminal stop:
        // each run rebuilds the batch under a new key and
        // attempts the same $2 again.
        $this->assertCount(1, $this->gateway->chargeCalls);
        $this->assertSame(1, BillingRecord::query()->where('business_id', $business->id)->where('state', BillingRecord::STATE_FAILED)->count());
        $this->assertNull($event->fresh()->billing_record_id);

        $this->runCharge();
        $this->runCharge();
        $this->assertCount(3, $this->gateway->chargeCalls);
        $this->assertSame(3, BillingRecord::query()->where('business_id', $business->id)->where('state', BillingRecord::STATE_FAILED)->count());
        $this->assertSame(0, BillingRecord::query()->where('business_id', $business->id)->where('state', BillingRecord::STATE_SUCCEEDED)->count());

        // FE-CANCEL-01 (GAP 3 fix): card REPLACE (and STORE,
        // for an ended owner who never had a card) is now the
        // ended owner's remedy while a balance is owed: the
        // closed middleware admits both under the controller's
        // balance gate, so the posts reach validation instead
        // of the closed redirect. Card REMOVE stays refused
        // (not asserted here; EndProgramTest T7 pins the full
        // matrix).
        $owner = World::owner($business);
        $this->actingAs($owner)->post(route('settings.billing.card.store'), [])
            ->assertSessionHasErrors(['source_id']);
        $this->actingAs($owner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.billing.card.replace'), [])
            ->assertSessionHasErrors(['source_id']);
    }

    public function test_b_record_only_mode_writes_a_fresh_simulated_record_on_every_run(): void
    {
        config(['billing.charges_enabled' => false]);
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        $event = $this->event($business, $membership);

        $this->closeBusiness($business);

        // BILL-17 record-only mode: a simulated record claims
        // nothing and never reaches the gateway, so the SAME owed
        // month is re-simulated on every run. No money moves; the
        // records accumulate until charges are enabled, when the
        // still-unclaimed events drain for real (previous test).
        $this->runCharge();
        $this->runCharge();

        $this->assertSame(2, BillingRecord::query()
            ->where('business_id', $business->id)
            ->where('state', BillingRecord::STATE_SIMULATED)
            ->count());
        $this->assertCount(0, $this->gateway->chargeCalls);
        $this->assertNull($event->fresh()->billing_record_id);
    }

    public function test_b_sub_minimum_campaign_balance_is_carried_forever_and_never_charged(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $this->cardOnFile($business);
        $rows = $this->attemptedRecipients($business, [2, 2, 2], Carbon::parse('2026-01-15 12:00:00'));

        $this->closeBusiness($business);

        // Six cents of attempted texts, no visits: below the $1
        // Square minimum, the batch "carries forward" — but a
        // closed business has no next month, so the carry is
        // terminal in practice. Nothing is ever charged and the
        // rows stay unclaimed.
        $this->runCharge();
        $this->runCharge();

        $this->assertSame(0, BillingRecord::query()->where('business_id', $business->id)->count());
        $this->assertCount(0, $this->gateway->chargeCalls);
        foreach ($rows as $row) {
            $this->assertNull($row->fresh()->billing_record_id);
        }
    }

    // ---------------------------------------------------------------
    // (c) Dunning on a closed business.
    // ---------------------------------------------------------------

    public function test_c_dunning_on_a_closed_past_due_business_is_bounded_to_one_episode(): void
    {
        $t0 = Carbon::parse('2026-03-10 12:00:00');
        $this->travelTo($t0);
        $business = World::business([
            'billing_status' => 'active',
            'square_customer_id' => 'sq_customer_cb',
            'free_visits_remaining' => 0,
        ]);
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 4200,
            'currency' => 'USD',
            'idempotency_key' => 'perref_'.$business->id.'_cb_1',
            'state' => BillingRecord::STATE_PENDING,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);

        // The failure lands and the business goes past_due BEFORE
        // closure; closing does not touch billing_status.
        app(SquareWebhookHandler::class)->handle([
            'type' => 'payment.updated',
            'data' => ['object' => ['payment' => [
                'id' => 'sq_pay_cb_failed', 'status' => 'FAILED', 'customer_id' => 'sq_customer_cb',
            ]]],
        ]);
        $this->assertSame('past_due', $business->fresh()->billing_status);
        $this->assertSame(BillingRecord::STATE_FAILED, $record->fresh()->state);

        $this->closeBusiness($business);
        $this->assertSame('past_due', $business->fresh()->billing_status);

        // The full specced schedule still runs against the closed
        // business: day 0, day 3, day 6 — each exactly once,
        // however often the hourly run executes afterwards.
        $this->artisan('billing:dunning')->assertExitCode(0);
        Mail::assertSent(DunningPaymentFailedMail::class);
        // +1 over the dunning count: FE-CANCEL-01's E-10 end
        // confirmation, sent at the tap.
        Mail::assertSentCount(2);

        $this->travelTo($t0->copy()->addDays(3));
        $this->artisan('billing:dunning')->assertExitCode(0);
        Mail::assertSent(DunningReminderMail::class);
        Mail::assertSentCount(3);

        $this->travelTo($t0->copy()->addDays(6));
        $this->artisan('billing:dunning')->assertExitCode(0);
        Mail::assertSent(DunningPauseWarningMail::class);
        Mail::assertSentCount(4);

        $this->travelTo($t0->copy()->addDays(30));
        $this->artisan('billing:dunning')->assertExitCode(0);
        $this->artisan('billing:dunning')->assertExitCode(0);
        Mail::assertSentCount(4);
        $this->assertSame(3, AuditLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'billing.dunning_email')
            ->count());
    }

    // ---------------------------------------------------------------
    // (d) Free credits and the cap on the final drain.
    // ---------------------------------------------------------------

    public function test_d_free_credits_and_monthly_cap_apply_on_the_final_drain(): void
    {
        $business = World::business(['free_visits_remaining' => 2]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        for ($i = 0; $i < 55; $i++) {
            $this->event($business, $membership, 200, Carbon::parse('2026-01-10 12:00:00'));
        }

        $this->closeBusiness($business);
        $this->runCharge();

        // The settlement walk runs unchanged for the closed
        // business: the 2 remaining free credits are consumed
        // first, 49 visits bill at the $98 cap, 4 fall over cap.
        $record = BillingRecord::query()->where('business_id', $business->id)->sole();
        $this->assertSame(9800, $record->amount_cents);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        $this->assertCount(1, $this->gateway->chargeCalls);
        $this->assertSame(2, BillingEvent::query()->where('business_id', $business->id)->where('coverage', 'free_credit')->count());
        $this->assertSame(49, BillingEvent::query()->where('business_id', $business->id)->where('coverage', 'billable')->count());
        $this->assertSame(4, BillingEvent::query()->where('business_id', $business->id)->where('coverage', 'over_cap')->count());
        $this->assertSame(0, (int) $business->fresh()->free_visits_remaining);

        // Nothing is left to re-settle into a second charge.
        $this->runCharge();
        $this->assertSame(1, BillingRecord::query()->where('business_id', $business->id)->count());
        $this->assertCount(1, $this->gateway->chargeCalls);
    }

    public function test_d_free_visit_credits_never_offset_the_text_campaign_leg(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);
        $this->cardOnFile($business);
        // Three visits, all inside the free allowance...
        for ($i = 0; $i < 3; $i++) {
            $this->event($business, $membership, 200, Carbon::parse('2026-01-10 12:00:00'));
        }
        // ...and $3 of attempted texts in the same month.
        $this->attemptedRecipients($business, [150, 150], Carbon::parse('2026-01-12 12:00:00'));

        $this->closeBusiness($business);
        $this->runCharge();

        // The visits settle free (no per-referral record at all);
        // the texts charge in full through their own
        // campaign-metering record. Free credit is a visit
        // allowance, never a wallet.
        $record = BillingRecord::query()->where('business_id', $business->id)->sole();
        $this->assertSame(BillingRecord::KIND_CAMPAIGN_METERING, $record->kind);
        $this->assertSame(300, $record->amount_cents);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        $this->assertCount(1, $this->gateway->chargeCalls);
        $this->assertSame(300, $this->gateway->chargeCalls[0]['amount_cents']);
        $this->assertSame(3, BillingEvent::query()->where('business_id', $business->id)->where('coverage', 'free_credit')->count());
        $this->assertSame(2, (int) $business->fresh()->free_visits_remaining);
    }
}
