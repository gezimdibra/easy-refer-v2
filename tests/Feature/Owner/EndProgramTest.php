<?php

namespace Tests\Feature\Owner;

use App\Contracts\SquareCustomerGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Mail\EndProgramMail;
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
use App\Services\Auth\StepUpService;
use App\Services\Billing\ChargeResult;
use App\Services\Billing\FinalBillService;
use App\Services\Billing\MonthlyCharger;
use App\Services\Billing\PendingRecordSweeper;
use App\Services\Billing\SettlementService;
use App\Services\Billing\SquareOutcomeUnknown;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Fakes\FakeSquareCustomerGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * FE-CANCEL-01 "End my program" (design pass 2026-10-30):
 * the 14-test plan. Money first: the final bill at the tap is
 * exactly the next monthly charges attempted early, per month;
 * ending never waits on payment; the sub-$1 residual is
 * written off; card replace (never remove) works while closed
 * with a balance; the allowance is once per owner identity.
 */
class EndProgramTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    private int $seq = 6000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        $this->app->instance(SquareCustomerGateway::class, new FakeSquareCustomerGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        config(['billing.charges_enabled' => true]);
        Mail::fake();
    }

    private function membership(Business $business): Membership
    {
        [$membership] = World::membership($business, World::customer('+1212555'.($this->seq++), 'Reg', 'Ular'));

        return $membership;
    }

    private function event(Business $business, Membership $membership, int $amount = 200, ?Carbon $at = null, array $overrides = []): BillingEvent
    {
        [$referral] = World::referral($membership, World::customer('+1212555'.($this->seq++), 'Fri', 'End'));
        $event = BillingEvent::create(array_merge([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => $amount,
        ], $overrides));
        if ($at !== null) {
            $event->forceFill(['created_at' => $at])->save();
        }

        return $event;
    }

    private function card(Business $business): PaymentMethod
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

    /** @param list<int> $amounts attempted text rows */
    private function texts(Business $business, array $amounts, ?Carbon $at = null): array
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
        foreach ($amounts as $i => $amount) {
            $customer = World::customer('+1212555'.($this->seq++), 'Cam', 'Paign');
            [$membership] = World::membership($business, $customer);
            $message = SmsMessage::create([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'phone_e164' => $customer->phone_e164,
                'purpose' => 'campaign',
                'messaging_service' => 'marketing',
                'template_key' => 'campaign',
                'rendered_body' => 'Spring sale is on',
                'twilio_message_sid' => 'SM_END_'.$campaign->id.'_'.$i.'_'.uniqid(),
                'delivery_state' => 'sent',
                'consent_basis' => 'customer_checkbox',
                'opt_out_state_at_send' => false,
            ]);
            $row = SmsCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'membership_id' => $membership->id,
                'customer_id' => $customer->id,
                'phone_e164' => $customer->phone_e164,
                'twilio_message_sid' => $message->twilio_message_sid,
                'sms_message_id' => $message->id,
                'delivery_state' => 'sent',
                'segments' => 1,
                'amount_cents' => $amount,
            ]);
            if ($at !== null) {
                $row->forceFill(['created_at' => $at])->save();
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function close(Business $business, array $data = [], string $expectRoute = 'dashboard'): void
    {
        $owner = World::owner($business);
        $this->actingAs($owner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.account.close'), array_merge(['confirm' => '1'], $data))
            ->assertRedirect(route($expectRoute));
    }

    // T1: final bill at the tap, per month, equals the walk.
    public function test_final_bill_at_tap_across_two_months_plus_texts(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->card($business);
        for ($i = 0; $i < 3; $i++) {
            $this->event($business, $membership, 200, Carbon::parse('2026-08-10'));
        }
        for ($i = 0; $i < 2; $i++) {
            $this->event($business, $membership, 200, Carbon::parse('2026-10-05'));
        }
        $this->texts($business, [120, 120], Carbon::parse('2026-10-06'));

        $this->close($business);

        $records = BillingRecord::query()->where('business_id', $business->id)->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertSame(600, $records[0]->amount_cents);
        $this->assertSame(640, $records[1]->amount_cents);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $records[0]->state);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $records[1]->state);
        $this->assertCount(2, $this->gateway->chargeCalls);

        $closed = AuditLog::query()->where('action', 'business.closed')->firstOrFail();
        $this->assertSame(1240, $closed->metadata['owed_cents'] ?? null);
        $this->assertSame(5, $closed->metadata['billable_events'] ?? null);
        $this->assertSame(240, $closed->metadata['text_cents'] ?? null);

        Mail::assertSent(EndProgramMail::class, fn ($mail) => $mail->outcome === 'paid' && $mail->amountCents === 1240);
    }

    // T2: free allowance + sub-$1 texts: no charge, write-off, no resurrection.
    public function test_sub_dollar_residual_is_written_off_and_never_retried(): void
    {
        $business = World::business(); // 5 free visits remain
        $membership = $this->membership($business);
        $this->card($business);
        for ($i = 0; $i < 3; $i++) {
            $this->event($business, $membership);
        }
        $rows = $this->texts($business, [40, 40]);

        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('settings.account'))
            ->assertOk()
            ->assertSee('You owe $0.80 in texts. That is under the $1 card minimum, so we will not charge it.');

        $this->close($business);

        $this->assertSame(0, BillingRecord::count());
        $this->assertCount(0, $this->gateway->chargeCalls);
        foreach ($rows as $row) {
            $this->assertNotNull($row->fresh()->written_off_at);
        }
        // A later monthly run charges nothing and resurrects nothing.
        $this->assertSame([], app(MonthlyCharger::class)->chargeAllMonths($business->fresh()));
        $this->assertSame(0, BillingRecord::count());
        Mail::assertSent(EndProgramMail::class, fn ($mail) => $mail->outcome === 'written_off');
    }

    // T3: cap binds inside the final bill exactly as in a monthly run.
    public function test_final_bill_respects_the_monthly_cap(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->card($business);
        for ($i = 0; $i < 60; $i++) {
            $this->event($business, $membership);
        }
        $this->texts($business, [200]);

        $this->close($business);

        $record = BillingRecord::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(9800 + 200, $record->amount_cents);
    }

    // T4: failed final charge: program still ended, debt released for retry.
    public function test_failed_final_charge_still_ends_the_program(): void
    {
        $this->gateway->chargeFailureCode = 'GENERIC_DECLINE';
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->card($business);
        $event = $this->event($business, $membership);
        $this->event($business, $membership);

        $this->close($business);

        $fresh = $business->fresh();
        $this->assertTrue($fresh->isClosed());
        $this->assertFalse($fresh->flowIsActive());
        $record = BillingRecord::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(BillingRecord::STATE_FAILED, $record->state);
        $this->assertNull($event->fresh()->billing_record_id);
        Mail::assertSent(EndProgramMail::class, fn ($mail) => $mail->outcome === 'failed' && $mail->amountCents === 400);
    }

    // T5: unknown outcome: record pending, no mail until the sweeper resolves.
    public function test_unknown_final_charge_sends_no_mail_until_resolved(): void
    {
        $this->gateway->chargeException = new SquareOutcomeUnknown('timeout');
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->card($business);
        $this->event($business, $membership);

        $this->close($business);

        $record = BillingRecord::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(BillingRecord::STATE_PENDING, $record->state);
        $this->assertNotNull(BillingEvent::query()->where('billing_record_id', $record->id)->first());
        Mail::assertNotSent(EndProgramMail::class);

        $this->gateway->resolvedCharges[$record->idempotency_key] = ChargeResult::succeeded('sq_pay_resolved', 'sq_ord_resolved');
        Carbon::setTestNow(now()->addMinutes(20));
        app(PendingRecordSweeper::class)->sweep(15);

        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->fresh()->state);
        Mail::assertSent(EndProgramMail::class, fn ($mail) => $mail->outcome === 'paid');
    }

    // T6: no-card ending with a balance: ends, balance visible, monthly run skips.
    public function test_no_card_ending_with_a_balance(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->event($business, $membership);
        $this->event($business, $membership);

        $this->close($business);

        $this->assertTrue($business->fresh()->isClosed());
        $this->assertSame(0, BillingRecord::count());
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('settings.billing'))
            ->assertOk()
            ->assertSee('is still owed from before you ended')
            ->assertSee('$4.00');
        $this->assertSame([], app(MonthlyCharger::class)->chargeAllMonths($business->fresh()));
    }

    // T7: card replace while closed+owed succeeds; remove refused;
    // replace while closed+not owed refused.
    public function test_card_replace_only_while_closed_and_owed(): void
    {
        $business = World::business(['free_visits_remaining' => 0, 'status' => 'closed', 'closed_at' => now()]);
        $owner = World::owner($business);
        $membership = $this->membership($business);
        $this->event($business, $membership);
        $this->card($business);

        $this->actingAs($owner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.billing.card.replace'), ['source_id' => 'cnon:replacement'])
            ->assertRedirect(route('settings.billing'));
        $this->assertNotEmpty($this->gateway->saveCardCalls);

        $this->actingAs($owner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.billing.card.remove'))
            ->assertRedirect(route('dashboard'));
        $this->assertNotNull(PaymentMethod::defaultFor($business->fresh()));

        $settled = World::business(['status' => 'closed', 'closed_at' => now()]);
        $settledOwner = World::owner($settled);
        $this->card($settled);
        $callsBefore = count($this->gateway->saveCardCalls);
        $this->actingAs($settledOwner)
            ->withSession([StepUpService::SESSION_KEY => now()->timestamp])
            ->post(route('settings.billing.card.replace'), ['source_id' => 'cnon:replacement2'])
            ->assertRedirect(route('dashboard'));
        $this->assertSame($callsBefore, count($this->gateway->saveCardCalls));

        // GAP 3 extension: a cardless ended owner WITH a
        // balance can add a first card through the store route.
        $cardless = World::business(['free_visits_remaining' => 0, 'status' => 'closed', 'closed_at' => now()]);
        $cardlessOwner = World::owner($cardless);
        $cardlessMembership = $this->membership($cardless);
        $this->event($cardless, $cardlessMembership);
        $this->actingAs($cardlessOwner)
            ->post(route('settings.billing.card.store'), ['source_id' => 'cnon:first'])
            ->assertRedirect(route('settings.billing'));
        $this->assertNotNull(PaymentMethod::defaultFor($cardless->fresh()));
    }

    // T8: held items stay resolvable after ending; a release mints no charge.
    public function test_held_items_resolvable_after_ending_without_billing(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+1212555'.($this->seq++), 'Ana', 'Ray'));
        [$redeemed] = World::referral($membership, World::customer('+1212555'.($this->seq++), 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        [$reward] = World::reward($membership, $redeemed, ['hold' => true, 'held_at' => now()]);
        [$redeemed2] = World::referral($membership, World::customer('+1212555'.($this->seq++), 'Gil', 'Two'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        [$reward2] = World::reward($membership, $redeemed2, ['hold' => true, 'held_at' => now()]);

        $this->close($business);

        $this->actingAs($owner)->post(route('reviews.rewards.release', ['reward' => $reward->id]));
        $this->assertFalse($reward->fresh()->hold);
        $this->assertSame(0, BillingEvent::query()->where('business_id', $business->id)->count());

        $this->actingAs($owner)->post(route('reviews.rewards.deny', ['reward' => $reward2->id]));
        $this->assertSame(Reward::STATE_DENIED, $reward2->fresh()->state);
    }

    // T9: the late-vesting day-30 edge, plus the in-window control.
    public function test_late_vesting_reward_expires_unclaimed_after_day_30(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $business = World::business(['status' => 'closed', 'closed_at' => now()]);
        [$membership] = World::membership($business, World::customer('+1212555'.($this->seq++), 'Ana', 'Ray'));
        [$redeemed] = World::referral($membership, World::customer('+1212555'.($this->seq++), 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(20),
        ]);
        // Vests on day 35 after the visit, i.e. after the window ends.
        [$lateReward, $latePlain] = World::reward($membership, $redeemed, [
            'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(15),
        ]);
        [$redeemed2] = World::referral($membership, World::customer('+1212555'.($this->seq++), 'Gil', 'Two'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(20),
        ]);
        [$earlyReward, $earlyPlain] = World::reward($membership, $redeemed2, [
            'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(9),
        ]);

        Carbon::setTestNow('2026-10-20 12:00:00'); // day 19: early one is available
        $this->get(route('refer.claim.page', ['token' => $earlyPlain]))->assertOk();
        $this->assertSame(Reward::STATE_AVAILABLE, $earlyReward->fresh()->state);

        Carbon::setTestNow('2026-11-01 12:00:00'); // day 31: window ended
        $this->get(route('refer.claim.page', ['token' => $latePlain]))->assertNotFound();
        $this->assertSame(Reward::STATE_EXPIRED, $lateReward->fresh()->state);
    }

    // T10: claimed-but-unvisited referrals void at the tap; the friend sees C-03.
    public function test_claimed_but_unvisited_referral_voids_and_shows_ended_wording(): void
    {
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+1212555'.($this->seq++), 'Ana', 'Ray'));
        [$pending, $plain] = World::referral($membership, World::customer('+1212555'.($this->seq++), 'Fay', 'One'));

        $owner = World::owner($business);
        $page = $this->actingAs($owner)->get(route('settings.account'));
        $page->assertSee('1 friends have claimed a reward and have not visited yet. Their rewards end when the program ends, because the visit has not happened.');

        $this->close($business);

        $this->assertSame(Referral::STATE_VOIDED, $pending->fresh()->state);
        $this->get(route('refer.redeem.page', ['token' => $plain]))
            ->assertOk()
            ->assertSee('This shop has ended its referral program, so this reward is no longer available.');
    }

    // T11: allowance is once per owner identity at settlement; controls keep it.
    public function test_allowance_once_per_owner_identity_at_settlement(): void
    {
        $first = World::business();
        $firstMembership = $this->membership($first);
        $this->event($first, $firstMembership, 200, null, ['coverage' => 'free_credit']);

        // Identity key: the Square customer id (users.
        // canonical_email is globally unique in this schema, so
        // two live owner rows can never share it; the Square
        // customer is the populated cross-business key).
        $first->forceFill(['square_customer_id' => 'sq_customer_shared'])->save();
        $second = World::business(['square_customer_id' => 'sq_customer_shared']);
        $secondMembership = $this->membership($second);
        $secondEvent = $this->event($second, $secondMembership);

        app(SettlementService::class)->settleAndPersist($second);
        $this->assertSame('billable', $secondEvent->fresh()->coverage);
        $this->assertSame(0, $second->fresh()->free_visits_remaining);
        $audit = AuditLog::query()->where('business_id', $second->id)->where('action', 'billing.allowance_once_applied')->first();
        $this->assertNotNull($audit);
        $this->assertSame($first->id, $audit->metadata['prior_business_id'] ?? null);

        $control = World::business();
        $controlMembership = $this->membership($control);
        $controlEvent = $this->event($control, $controlMembership);
        app(SettlementService::class)->settleAndPersist($control);
        $this->assertSame('free_credit', $controlEvent->fresh()->coverage);
    }

    // T12: copy truth sweep over the named states.
    public function test_end_program_copy_renders_only_in_its_states(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $account = $this->actingAs($owner)->get(route('settings.account'))->assertOk();
        $account->assertSee('End my program');
        $account->assertSee('Ending your program turns your QR code off right away. No new visits or texts are charged after you end. Rewards your customers already earned stay good for 30 days after you end, or until their own use by date if that comes first. Your account stays open so you can sign in and see your history. Nothing is deleted.');
        $account->assertSee('You owe nothing right now. Ending today means no final bill.');
        $account->assertSee('I understand what ending my program does.');
        $account->assertDontSee('Close your account');
        $account->assertDontSee('Close account');

        $index = $this->actingAs($owner)->get(route('settings'))->assertOk();
        $index->assertSee('End your referral program, settle your final bill, and see what happens to rewards.');
        $index->assertDontSee('Close your account');

        $billing = $this->actingAs($owner)->get(route('settings.billing'))->assertOk();
        $billing->assertSee('To stop billing, end your program in Settings, under Account. Your final bill covers the visits and texts you already have.');
        $billing->assertDontSee('close your account');

        $this->get(route('faq'))->assertOk()
            ->assertSee('End your program any time in Settings, under Account. Your code turns off right away, we charge one final bill for the visits and texts you already have, and rewards your customers already earned stay good for 30 days, or until their own use by date if that comes first. Your account stays open so you can see your history.')
            ->assertDontSee('Close your account any time');

        // Past due: the episode line names the amount, and only there.
        $pastDue = World::business(['billing_status' => 'past_due']);
        $pastOwner = World::owner($pastDue);
        $record = BillingRecord::create([
            'business_id' => $pastDue->id, 'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 1200, 'currency' => 'USD', 'idempotency_key' => 'pd_'.$pastDue->id,
            'state' => BillingRecord::STATE_FAILED, 'period_start' => now()->subMonth()->startOfMonth(), 'period_end' => now()->subMonth()->endOfMonth(),
        ]);
        AuditLog::write('billing.past_due', null, [], $pastDue, $record);
        $this->actingAs($pastOwner)->get(route('settings.account'))->assertOk()
            ->assertSee('You also have a past-due amount of $12.00');

        // Closed inside the window: deadline sentence true.
        $closed = World::business(['status' => 'closed', 'closed_at' => now()->subDays(3)]);
        $closedOwner = World::owner($closed);
        $dash = $this->actingAs($closedOwner)->get(route('dashboard'))->assertOk();
        $dash->assertSee('stay claimable until');
        $acct = $this->actingAs($closedOwner)->get(route('settings.account'))->assertOk();
        $acct->assertSee('Your program ended on');
        $acct->assertSee('Ended programs do not restart on their own. If you want to run referrals again, contact us and we will set you up. Free visits are once per business, so a restarted program needs a card on file before the code turns on.');

        // Closed past day 30: the deadline sentence must NOT render.
        $ended = World::business(['status' => 'closed', 'closed_at' => now()->subDays(31)]);
        $endedOwner = World::owner($ended);
        $dash31 = $this->actingAs($endedOwner)->get(route('dashboard'))->assertOk();
        $dash31->assertDontSee('stay claimable until');
        $dash31->assertSee('The 30 days for claiming rewards ended on');
        $acct31 = $this->actingAs($endedOwner)->get(route('settings.account'))->assertOk();
        $acct31->assertSee('The 30 days for claiming rewards ended on');
        $acct31->assertDontSee('stay claimable until');
    }

    // T13: friction structure: 1 checkbox, 0 required authored fields,
    // reason chips optional.
    public function test_end_flow_friction_structure(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $html = $this->actingAs($owner)->get(route('settings.account'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="confirm"'));
        $this->assertStringContainsString('name="end_reason"', $html);
        // The reason is never required, and no text input is required.
        $this->assertDoesNotMatchRegularExpression('/name="end_reason"[^>]*required/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="text"[^>]*required/', $html);
        $this->assertStringContainsString('End my program', $html);
        $this->assertStringContainsString('Keep my program', $html);
    }

    // T14: double submission charges nothing twice.
    public function test_double_end_submission_is_idempotent(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->card($business);
        $this->event($business, $membership);

        $this->close($business);
        $this->close($business, [], 'settings.account');

        $this->assertSame(1, BillingRecord::count());
        $this->assertCount(1, $this->gateway->chargeCalls);
        $this->assertSame(1, AuditLog::query()->where('action', 'business.closed')->count());
    }

    // ---- Two-key merge condition pins (T15-T18): FinalBillService::attempt
    // must be idempotent under RunFinalBill's $tries = 3 / at-least-once
    // delivery. A retry derives its outcome from the final-bill records
    // the first attempt already left behind, never from the empty set of
    // records its own invocation happens to create.

    private function closedBusinessWithCard(int $events = 2): Business
    {
        $business = World::business(['free_visits_remaining' => 0, 'status' => 'closed', 'closed_at' => now()]);
        $membership = $this->membership($business);
        $this->card($business);
        for ($i = 0; $i < $events; $i++) {
            $this->event($business, $membership);
        }

        return $business;
    }

    /** @return list<BillingRecord> */
    private function attemptFinalBill(Business $business, int $owedCents): array
    {
        return app(FinalBillService::class)->attempt($business, [
            'owed_cents' => $owedCents, 'billable_events' => 0, 'text_cents' => 0,
        ]);
    }

    // T15 (pin 1): double attempt after success = exactly one paid
    // mail total, zero failed mails, no second charge.
    public function test_double_attempt_after_success_sends_one_paid_mail_and_no_failed(): void
    {
        $business = $this->closedBusinessWithCard();

        $this->attemptFinalBill($business, 400);
        Mail::assertSentTimes(EndProgramMail::class, 1);

        $this->attemptFinalBill($business->fresh(), 400);

        Mail::assertSentTimes(EndProgramMail::class, 1);
        $this->assertSame(1, Mail::sent(EndProgramMail::class, fn ($mail) => $mail->outcome === 'paid' && $mail->amountCents === 400)->count());
        $this->assertSame(0, Mail::sent(EndProgramMail::class, fn ($mail) => $mail->outcome === 'failed')->count());
        $this->assertSame(1, BillingRecord::count());
        $this->assertCount(1, $this->gateway->chargeCalls);
    }

    // T16 (pin 2): re-attempt while a record is pending = no outcome
    // mail at all (the sweeper owns the resolution).
    public function test_reattempt_while_record_is_pending_sends_no_outcome_mail(): void
    {
        $this->gateway->chargeException = new SquareOutcomeUnknown('timeout');
        $business = $this->closedBusinessWithCard(1);

        $this->attemptFinalBill($business, 200);
        $record = BillingRecord::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(BillingRecord::STATE_PENDING, $record->state);
        Mail::assertNotSent(EndProgramMail::class);

        $this->attemptFinalBill($business->fresh(), 200);

        Mail::assertNotSent(EndProgramMail::class);
        $this->assertSame(1, BillingRecord::count());
        $this->assertSame(BillingRecord::STATE_PENDING, $record->fresh()->state);
    }

    // T17 (pin 3): double attempt after a genuine failure = exactly
    // one failed mail, and the retry does not charge the card again.
    public function test_double_attempt_after_failure_sends_one_failed_mail_and_does_not_recharge(): void
    {
        $this->gateway->chargeFailureCode = 'GENERIC_DECLINE';
        $business = $this->closedBusinessWithCard();

        $this->attemptFinalBill($business, 400);
        Mail::assertSentTimes(EndProgramMail::class, 1);

        $this->attemptFinalBill($business->fresh(), 400);

        Mail::assertSentTimes(EndProgramMail::class, 1);
        $this->assertSame(1, Mail::sent(EndProgramMail::class, fn ($mail) => $mail->outcome === 'failed' && $mail->amountCents === 400)->count());
        $this->assertSame(0, Mail::sent(EndProgramMail::class, fn ($mail) => $mail->outcome === 'paid')->count());
        $this->assertSame(1, BillingRecord::count());
        $this->assertCount(1, $this->gateway->chargeCalls);
    }

    // T18 (pin 4): the reviewer's exact scenario — the charge
    // succeeds, then Mail::send throws post-charge, so the job
    // retries with no end_mail_sent ledger row. The retry must
    // converge on the paid form only.
    public function test_mail_throwing_after_charge_retries_to_paid_form_only(): void
    {
        $business = $this->closedBusinessWithCard();

        $fakeRoot = Mail::getFacadeRoot();
        $manager = $fakeRoot instanceof MailFake ? $fakeRoot->manager : $this->app->make(MailManager::class);
        $throwing = new class($manager) extends MailFake
        {
            public function send($view, array $data = [], $callback = null)
            {
                throw new \RuntimeException('mail transport failed after charge');
            }
        };
        Mail::swap($throwing);

        try {
            $this->attemptFinalBill($business, 400);
            $this->fail('Expected the post-charge mail failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('mail transport failed after charge', $e->getMessage());
        }

        // The charge landed; the mail (and its ledger row) did not.
        $record = BillingRecord::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        $this->assertSame(0, AuditLog::query()->where('action', 'business.end_mail_sent')->count());

        Mail::fake();
        $this->attemptFinalBill($business->fresh(), 400);

        Mail::assertSentTimes(EndProgramMail::class, 1);
        $this->assertSame(1, Mail::sent(EndProgramMail::class, fn ($mail) => $mail->outcome === 'paid' && $mail->amountCents === 400)->count());
        $this->assertSame(0, Mail::sent(EndProgramMail::class, fn ($mail) => $mail->outcome === 'failed')->count());
        $this->assertSame(1, BillingRecord::count());
        $this->assertCount(1, $this->gateway->chargeCalls);
    }

    // ---- Amendment 69 pins (T19-T25): the ONE E-10 email reports
    // the COMPLETE final-bill outcome — every leg class with its
    // own figure — on every send path. The M-3 two-key advisory
    // residual: succeeded+failed mailed only the failed cents;
    // succeeded+written-off mailed only the charged cents.

    private function sentEndMail(): EndProgramMail
    {
        $sent = Mail::sent(EndProgramMail::class);
        $this->assertCount(1, $sent, 'Exactly one E-10 email per ending, whatever the outcome mix.');

        return $sent->first();
    }

    // T19: the composition itself, all seven leg combinations
    // plus zero, at the mailable level. Single legs keep their
    // original E-10 sentences verbatim; mixed outcomes render
    // one line per leg and never a combined figure.
    public function test_bill_lines_compose_every_leg_combination(): void
    {
        $business = World::business(['status' => 'closed', 'closed_at' => now()]);
        $lines = fn (int $charged, int $failed, int $writtenOff) => (new EndProgramMail(
            $business,
            $failed > 0 ? 'failed' : ($charged > 0 ? 'paid' : ($writtenOff > 0 ? 'written_off' : 'zero')),
            $failed ?: ($charged ?: $writtenOff),
            $charged,
            $failed,
            $writtenOff,
        ))->billLines();

        $this->assertSame(['You owed nothing, so there is no final bill.'], $lines(0, 0, 0));
        $this->assertSame(['Your final bill of $4 was charged to your card.'], $lines(400, 0, 0));
        $this->assertSame(
            ['Your final bill of $4 did not go through. Your program has still ended. We will try the card once a month, and you can update your card any time in Settings, under Billing.'],
            $lines(0, 400, 0),
        );
        $this->assertSame(
            ['Your final bill of $0.80 was under the $1 card minimum, so we did not charge it.'],
            $lines(0, 0, 80),
        );
        $this->assertSame(
            [
                'We charged $4 to your card.',
                'We could not collect $2. Your program has still ended. We will try the card once a month, and you can update your card any time in Settings, under Billing.',
            ],
            $lines(400, 200, 0),
        );
        $this->assertSame(
            [
                'We charged $4 to your card.',
                '$0.80 was under the $1 card minimum, so we did not charge it.',
            ],
            $lines(400, 0, 80),
        );
        $this->assertSame(
            [
                'We could not collect $4. Your program has still ended. We will try the card once a month, and you can update your card any time in Settings, under Billing.',
                '$0.80 was under the $1 card minimum, so we did not charge it.',
            ],
            $lines(0, 400, 80),
        );
        $this->assertSame(
            [
                'We charged $4 to your card.',
                'We could not collect $2. Your program has still ended. We will try the card once a month, and you can update your card any time in Settings, under Billing.',
                '$0.80 was under the $1 card minimum, so we did not charge it.',
            ],
            $lines(400, 200, 80),
        );
    }

    // T20: fresh drain, charged + failed (the drain stops at the
    // first failure after a month succeeded). One mail, both
    // legs, ledger figures complete.
    public function test_mixed_charged_and_failed_drain_mails_both_legs_once(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $flaky = new class extends FakeSquarePaymentsGateway
        {
            public function chargeCardOnFile(Business $business, PaymentMethod $card, int $amountCents, string $idempotencyKey, array $lineItems): ChargeResult
            {
                if (count($this->chargeCalls) >= 1) {
                    $this->chargeFailureCode ??= 'GENERIC_DECLINE';
                }

                return parent::chargeCardOnFile($business, $card, $amountCents, $idempotencyKey, $lineItems);
            }
        };
        $this->app->instance(SquarePaymentsGateway::class, $flaky);

        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->card($business);
        $this->event($business, $membership, 200, Carbon::parse('2026-08-10'));
        $this->event($business, $membership, 200, Carbon::parse('2026-08-10'));
        $this->event($business, $membership, 200, Carbon::parse('2026-10-05'));
        $this->event($business, $membership, 200, Carbon::parse('2026-10-05'));

        $this->close($business);

        $records = BillingRecord::query()->where('business_id', $business->id)->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $records[0]->state);
        $this->assertSame(BillingRecord::STATE_FAILED, $records[1]->state);

        $mail = $this->sentEndMail();
        $this->assertSame('failed', $mail->outcome);
        $this->assertSame(400, $mail->amountCents);
        $this->assertSame(400, $mail->chargedCents);
        $this->assertSame(400, $mail->failedCents);
        $this->assertSame(0, $mail->writtenOffCents);
        $body = $mail->render();
        $this->assertStringContainsString('We charged $4 to your card.', $body);
        $this->assertStringContainsString('We could not collect $4.', $body);
        $this->assertStringNotContainsString('card minimum', $body);

        $ledger = AuditLog::query()->where('action', 'business.end_mail_sent')->firstOrFail();
        $this->assertSame(400, $ledger->metadata['charged_cents'] ?? null);
        $this->assertSame(400, $ledger->metadata['failed_cents'] ?? null);
        $this->assertSame(0, $ledger->metadata['written_off_cents'] ?? null);
    }

    // T21: fresh drain, charged + written-off (a month charges,
    // a later month's sub-$1 texts are the residual).
    public function test_mixed_charged_and_written_off_drain_mails_both_legs_once(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->card($business);
        $this->event($business, $membership, 200, Carbon::parse('2026-08-10'));
        $this->event($business, $membership, 200, Carbon::parse('2026-08-10'));
        $this->texts($business, [40, 40], Carbon::parse('2026-10-06'));

        $this->close($business);

        $record = BillingRecord::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $record->state);
        $this->assertSame(400, $record->amount_cents);

        $mail = $this->sentEndMail();
        $this->assertSame('paid', $mail->outcome);
        $this->assertSame(400, $mail->chargedCents);
        $this->assertSame(0, $mail->failedCents);
        $this->assertSame(80, $mail->writtenOffCents);
        $body = $mail->render();
        $this->assertStringContainsString('We charged $4 to your card.', $body);
        $this->assertStringContainsString('$0.80 was under the $1 card minimum, so we did not charge it.', $body);
        $this->assertStringNotContainsString('could not collect', $body);
    }

    // T22: convergence, failed + written-off: a previous
    // invocation left a failed record and a write-off audit, then
    // died before the mail. The retry mails both legs and
    // charges nothing.
    public function test_converged_failed_and_written_off_mails_both_legs_without_recharging(): void
    {
        $business = World::business(['free_visits_remaining' => 0, 'status' => 'closed', 'closed_at' => now()]);
        World::owner($business);
        BillingRecord::create([
            'business_id' => $business->id, 'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => 400, 'currency' => 'USD', 'idempotency_key' => 'mix_fw_'.$business->id,
            'state' => BillingRecord::STATE_FAILED, 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
        ]);
        AuditLog::write('billing.final_bill_written_off', null, ['amount_cents' => 80, 'billing_events' => 0, 'text_rows' => 2], $business);

        $this->attemptFinalBill($business, 480);

        $mail = $this->sentEndMail();
        $this->assertSame(0, $mail->chargedCents);
        $this->assertSame(400, $mail->failedCents);
        $this->assertSame(80, $mail->writtenOffCents);
        $body = $mail->render();
        $this->assertStringContainsString('We could not collect $4.', $body);
        $this->assertStringContainsString('$0.80 was under the $1 card minimum, so we did not charge it.', $body);
        $this->assertSame(1, BillingRecord::count());
        $this->assertCount(0, $this->gateway->chargeCalls);
    }

    // T23: convergence, all three legs at once — and a second
    // attempt still sends nothing (the ledger discipline from
    // 5106b89 holds for mixed outcomes too).
    public function test_converged_all_three_legs_mail_once_and_retry_sends_nothing(): void
    {
        $business = World::business(['free_visits_remaining' => 0, 'status' => 'closed', 'closed_at' => now()]);
        World::owner($business);
        foreach ([[400, BillingRecord::STATE_SUCCEEDED], [200, BillingRecord::STATE_FAILED]] as $i => [$amount, $state]) {
            BillingRecord::create([
                'business_id' => $business->id, 'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
                'amount_cents' => $amount, 'currency' => 'USD', 'idempotency_key' => 'mix_all_'.$business->id.'_'.$i,
                'state' => $state, 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
            ]);
        }
        AuditLog::write('billing.final_bill_written_off', null, ['amount_cents' => 80, 'billing_events' => 0, 'text_rows' => 2], $business);

        $this->attemptFinalBill($business, 680);
        $this->attemptFinalBill($business->fresh(), 680);

        $mail = $this->sentEndMail();
        $this->assertSame(400, $mail->chargedCents);
        $this->assertSame(200, $mail->failedCents);
        $this->assertSame(80, $mail->writtenOffCents);
        $body = $mail->render();
        $this->assertStringContainsString('We charged $4 to your card.', $body);
        $this->assertStringContainsString('We could not collect $2.', $body);
        $this->assertStringContainsString('$0.80 was under the $1 card minimum, so we did not charge it.', $body);
        $this->assertSame(2, BillingRecord::count());
        $this->assertCount(0, $this->gateway->chargeCalls);
    }

    /** Drain where month A succeeds and month B goes unknown. */
    private function closeWithSecondMonthUnknown(): Business
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $flaky = new class extends FakeSquarePaymentsGateway
        {
            public function chargeCardOnFile(Business $business, PaymentMethod $card, int $amountCents, string $idempotencyKey, array $lineItems): ChargeResult
            {
                if (count($this->chargeCalls) >= 1 && $this->chargeException === null) {
                    $this->chargeException = new SquareOutcomeUnknown('timeout');
                }

                return parent::chargeCardOnFile($business, $card, $amountCents, $idempotencyKey, $lineItems);
            }
        };
        $this->app->instance(SquarePaymentsGateway::class, $flaky);
        $this->gateway = $flaky;

        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $this->card($business);
        $this->event($business, $membership, 200, Carbon::parse('2026-08-10'));
        $this->event($business, $membership, 200, Carbon::parse('2026-08-10'));
        $this->event($business, $membership, 200, Carbon::parse('2026-10-05'));
        $this->event($business, $membership, 200, Carbon::parse('2026-10-05'));

        $this->close($business);

        $records = BillingRecord::query()->where('business_id', $business->id)->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertSame(BillingRecord::STATE_SUCCEEDED, $records[0]->state);
        $this->assertSame(BillingRecord::STATE_PENDING, $records[1]->state);
        Mail::assertNotSent(EndProgramMail::class);

        return $business;
    }

    // T24: sweeper resolution, succeeded leg: the mail reports
    // the whole charged outcome (the earlier month's $4 plus the
    // resolved $4 as ONE charged leg), not the resolved record's
    // figure alone.
    public function test_resolution_after_partial_success_reports_the_whole_charged_leg(): void
    {
        $business = $this->closeWithSecondMonthUnknown();
        $pending = BillingRecord::query()->where('business_id', $business->id)
            ->where('state', BillingRecord::STATE_PENDING)->firstOrFail();
        $this->gateway->resolvedCharges[$pending->idempotency_key] = ChargeResult::succeeded('sq_pay_late', 'sq_ord_late');
        Carbon::setTestNow(now()->addMinutes(20));
        app(PendingRecordSweeper::class)->sweep(15);

        $mail = $this->sentEndMail();
        $this->assertSame('paid', $mail->outcome);
        $this->assertSame(800, $mail->chargedCents);
        $this->assertSame(800, $mail->amountCents);
        $this->assertStringContainsString('Your final bill of $8 was charged to your card.', $mail->render());
    }

    // T25: sweeper resolution, failed leg: the mail reports the
    // month that DID charge beside the month that could not be
    // collected — the mixed outcome, in the one email.
    public function test_resolution_as_failed_after_partial_success_reports_both_legs(): void
    {
        $business = $this->closeWithSecondMonthUnknown();
        $pending = BillingRecord::query()->where('business_id', $business->id)
            ->where('state', BillingRecord::STATE_PENDING)->firstOrFail();
        $this->gateway->resolvedCharges[$pending->idempotency_key] = ChargeResult::failed('GENERIC_DECLINE', 'api');
        Carbon::setTestNow(now()->addMinutes(20));
        app(PendingRecordSweeper::class)->sweep(15);

        $mail = $this->sentEndMail();
        $this->assertSame(400, $mail->chargedCents);
        $this->assertSame(400, $mail->failedCents);
        $body = $mail->render();
        $this->assertStringContainsString('We charged $4 to your card.', $body);
        $this->assertStringContainsString('We could not collect $4.', $body);
    }

    /** A final-bill record in a given state at a given creation time. */
    private function finalBillRecord(Business $business, int $amount, string $state, ?Carbon $createdAt = null): BillingRecord
    {
        $record = BillingRecord::create([
            'business_id' => $business->id, 'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => $amount, 'currency' => 'USD',
            'idempotency_key' => 'fb_'.($this->seq++).'_'.$business->id,
            'state' => $state, 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
        ]);
        if ($createdAt !== null) {
            $record->forceFill(['created_at' => $createdAt])->save();
        }

        return $record;
    }

    // ---- Amendment 69 two-key BLOCK pins (B1/B2): the three
    // notifyResolved sub-changes (deferral, general-ledger dedupe,
    // sweeper-path residual write-off) ship with pins, and the
    // deferral covers the same closed_at - 5 minutes admission
    // window notifyResolved itself uses for the triggering record.

    // B2: grace-window deferral. The triggering record is in the
    // standard window (at closed_at); its still-pending sibling
    // was created 2 minutes BEFORE closed_at — inside the 5-minute
    // grace window the function admits for a trigger, but outside
    // finalBillRecords()'s at/after-closed_at scan. The outcome
    // mail must NOT fire while that sibling's leg is unknown, and
    // the sibling's own resolution then sends the complete mail.
    public function test_resolution_defers_while_a_grace_window_sibling_is_still_pending(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $closedAt = now();
        $business = World::business(['free_visits_remaining' => 0, 'status' => 'closed', 'closed_at' => $closedAt]);
        World::owner($business);

        $first = $this->finalBillRecord($business, 400, BillingRecord::STATE_SUCCEEDED, $closedAt->copy());
        $grace = $this->finalBillRecord($business, 200, BillingRecord::STATE_PENDING, $closedAt->copy()->subMinutes(2));

        app(FinalBillService::class)->notifyResolved($first->fresh());

        Mail::assertNotSent(EndProgramMail::class);
        $this->assertSame(0, AuditLog::query()->where('action', 'business.end_mail_sent')->count());

        $grace->forceFill(['state' => BillingRecord::STATE_FAILED])->save();
        app(FinalBillService::class)->notifyResolved($grace->fresh());

        $mail = $this->sentEndMail();
        $this->assertSame(400, $mail->chargedCents);
        $this->assertSame(200, $mail->failedCents);
        $body = $mail->render();
        $this->assertStringContainsString('We charged $4 to your card.', $body);
        $this->assertStringContainsString('We could not collect $2.', $body);
    }

    // B1(a): deferral, standard window. Two pending final-bill
    // records at/after closed_at; the first resolution sends
    // nothing and writes no ledger row while the second is still
    // pending, and the second resolution sends exactly one mail
    // carrying the complete legs of both.
    public function test_resolution_defers_while_a_standard_window_sibling_is_still_pending(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $closedAt = now();
        $business = World::business(['free_visits_remaining' => 0, 'status' => 'closed', 'closed_at' => $closedAt]);
        World::owner($business);

        $first = $this->finalBillRecord($business, 400, BillingRecord::STATE_PENDING, $closedAt->copy());
        $second = $this->finalBillRecord($business, 200, BillingRecord::STATE_PENDING, $closedAt->copy());

        $first->forceFill(['state' => BillingRecord::STATE_SUCCEEDED])->save();
        app(FinalBillService::class)->notifyResolved($first->fresh());

        Mail::assertNotSent(EndProgramMail::class);
        $this->assertSame(0, AuditLog::query()->where('action', 'business.end_mail_sent')->count());

        $second->forceFill(['state' => BillingRecord::STATE_SUCCEEDED])->save();
        app(FinalBillService::class)->notifyResolved($second->fresh());

        $mail = $this->sentEndMail();
        $this->assertSame('paid', $mail->outcome);
        $this->assertSame(600, $mail->chargedCents);
        $this->assertSame(600, $mail->amountCents);
        $this->assertStringContainsString('Your final bill of $6 was charged to your card.', $mail->render());
        $this->assertSame(1, AuditLog::query()->where('action', 'business.end_mail_sent')->count());
    }

    // B1(b): general-ledger dedupe. The ending's outcome mail
    // already sent via attempt() (the business.end_mail_sent
    // ledger row exists); a later notifyResolved() for a resolved
    // record of the same ending must stay silent and charge-free.
    public function test_notify_resolved_after_the_outcome_mail_already_sent_sends_and_charges_nothing(): void
    {
        $business = World::business(['free_visits_remaining' => 0, 'status' => 'closed', 'closed_at' => now()]);
        World::owner($business);
        $record = $this->finalBillRecord($business, 400, BillingRecord::STATE_SUCCEEDED);

        $this->attemptFinalBill($business, 400);
        Mail::assertSentTimes(EndProgramMail::class, 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'business.end_mail_sent')->count());

        app(FinalBillService::class)->notifyResolved($record->fresh());

        Mail::assertSentTimes(EndProgramMail::class, 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'business.end_mail_sent')->count());
        $this->assertSame(1, BillingRecord::count());
        $this->assertCount(0, $this->gateway->chargeCalls);
    }

    // B1(c): sweeper-path write-off. A pending record resolves
    // while a REAL sub-$1 residual (80 cents of unclaimed texts)
    // is still on the books: notifyResolved() itself settles it
    // through writeOffResidual() — the attempt() path returned
    // early while the record was pending and never reached the
    // write-off — and the one mail carries the written-off leg
    // beside the resolved record's charged leg.
    public function test_sweeper_resolution_settles_a_real_sub_dollar_residual_and_mails_its_leg(): void
    {
        $business = World::business(['free_visits_remaining' => 0, 'status' => 'closed', 'closed_at' => now()]);
        World::owner($business);
        $rows = $this->texts($business, [40, 40]);
        $record = $this->finalBillRecord($business, 400, BillingRecord::STATE_PENDING);

        $this->assertSame(0, AuditLog::query()->where('action', 'billing.final_bill_written_off')->count());
        foreach ($rows as $row) {
            $this->assertNull($row->fresh()->written_off_at);
        }

        $record->forceFill(['state' => BillingRecord::STATE_SUCCEEDED])->save();
        app(FinalBillService::class)->notifyResolved($record->fresh());

        $writeOff = AuditLog::query()->where('action', 'billing.final_bill_written_off')->firstOrFail();
        $this->assertSame(80, $writeOff->metadata['amount_cents'] ?? null);
        $this->assertSame(2, $writeOff->metadata['text_rows'] ?? null);
        foreach ($rows as $row) {
            $this->assertNotNull($row->fresh()->written_off_at);
        }

        $mail = $this->sentEndMail();
        $this->assertSame('paid', $mail->outcome);
        $this->assertSame(400, $mail->chargedCents);
        $this->assertSame(0, $mail->failedCents);
        $this->assertSame(80, $mail->writtenOffCents);
        $body = $mail->render();
        $this->assertStringContainsString('We charged $4 to your card.', $body);
        $this->assertStringContainsString('$0.80 was under the $1 card minimum, so we did not charge it.', $body);

        $ledger = AuditLog::query()->where('action', 'business.end_mail_sent')->firstOrFail();
        $this->assertSame(400, $ledger->metadata['charged_cents'] ?? null);
        $this->assertSame(80, $ledger->metadata['written_off_cents'] ?? null);
    }

    // ---- Amendment 70 pins (T26): the S0 rewards-outstanding
    // count line [E-12]. The count is the Rewards page's live
    // buckets (pending_vesting + available, held or not);
    // terminal rewards never count; the line is absent at zero.

    private function rewardInState(Business $business, string $state, bool $hold = false, array $extra = []): Reward
    {
        $membership = $this->membership($business);
        [$referral] = World::referral($membership, World::customer('+1212555'.($this->seq++), 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        [$reward] = World::reward($membership, $referral, array_merge([
            'state' => $state,
            'hold' => $hold,
            'held_at' => $hold ? now() : null,
            'available_at' => $state === Reward::STATE_PENDING_VESTING ? now()->addDays(7) : now()->subDay(),
        ], $extra));

        return $reward;
    }

    public function test_s0_outstanding_rewards_count_line_counts_live_states_only(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->rewardInState($business, Reward::STATE_AVAILABLE);
        $this->rewardInState($business, Reward::STATE_AVAILABLE);
        $this->rewardInState($business, Reward::STATE_PENDING_VESTING);
        $this->rewardInState($business, Reward::STATE_AVAILABLE, hold: true);
        // Terminal rewards are not outstanding and never count.
        $this->rewardInState($business, Reward::STATE_CLAIMED);
        $this->rewardInState($business, Reward::STATE_DENIED);
        $this->rewardInState($business, Reward::STATE_EXPIRED);

        $this->actingAs($owner)->get(route('settings.account'))
            ->assertOk()
            ->assertSee('You have 4 rewards still outstanding. They stay good until 30 days after you end. Any that are not claimed by then run out.');

        // Singular form, and absence at zero.
        $solo = World::business();
        $soloOwner = World::owner($solo);
        $this->rewardInState($solo, Reward::STATE_AVAILABLE);
        $this->actingAs($soloOwner)->get(route('settings.account'))
            ->assertOk()
            ->assertSee('You have 1 reward still outstanding. It stays good until 30 days after you end. Any that are not claimed by then run out.');

        $empty = World::business();
        $emptyOwner = World::owner($empty);
        $this->actingAs($emptyOwner)->get(route('settings.account'))
            ->assertOk()
            ->assertDontSee('still outstanding');
    }

    // ---- M4-E12-01 pins: [E-12] reads post-transition state
    // (the same set-level trio the Rewards page runs) so its
    // count agrees with the Rewards page, and its duration
    // branches when a counted reward's own use-by date lands
    // inside the prospective 30-day window. The check report's
    // probes P1-P4 convert directly.

    // P-E12-B (probe P1): an available, unheld reward past its
    // own date is expired BY the settings read itself, so the
    // line is absent and the stored state is expired.
    public function test_s0_outstanding_line_absent_for_time_expired_available_reward_after_settings_read(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $reward = $this->rewardInState($business, Reward::STATE_AVAILABLE, extra: [
            'available_at' => now()->subMonths(13),
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($owner)->get(route('settings.account'))
            ->assertOk()
            ->assertDontSee('still outstanding');

        $this->assertSame(Reward::STATE_EXPIRED, $reward->fresh()->state);
    }

    // P-E12-C (probe P2): a both-due pending_vesting reward
    // (vesting date and use-by date both past) vests and then
    // time-expires inside the settings read; it is not counted.
    public function test_s0_outstanding_line_absent_for_both_due_pending_vesting_reward(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $reward = $this->rewardInState($business, Reward::STATE_PENDING_VESTING, extra: [
            'available_at' => now()->subMonths(13),
            'expires_at' => now()->subMonth(),
        ]);

        $this->actingAs($owner)->get(route('settings.account'))
            ->assertOk()
            ->assertDontSee('still outstanding');

        $this->assertSame(Reward::STATE_EXPIRED, $reward->fresh()->state);
    }

    // P-E12-D (probe P3, the anti-naive-filter pin): a HELD
    // available reward past its stamped date is still
    // outstanding — the clock pauses under a hold — and the
    // settings read must not expire it. A date filter on the
    // count query would wrongly drop this reward.
    public function test_s0_outstanding_line_still_counts_held_reward_past_its_date(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $reward = $this->rewardInState($business, Reward::STATE_AVAILABLE, hold: true, extra: [
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($owner)->get(route('settings.account'))
            ->assertOk()
            ->assertSee('You have 1 reward still outstanding.');

        $fresh = $reward->fresh();
        $this->assertSame(Reward::STATE_AVAILABLE, $fresh->state);
        $this->assertTrue((bool) $fresh->hold);
    }

    // P-E12-A (probe P4): the duration branch, both directions.
    // A counted reward whose own date lands inside the
    // prospective window branches the sentence (singular "its",
    // plural "their"); with no such reward the original
    // Amendment 70 sentence renders byte-identically.
    public function test_s0_outstanding_line_branches_when_own_date_inside_window(): void
    {
        $solo = World::business();
        $soloOwner = World::owner($solo);
        $this->rewardInState($solo, Reward::STATE_AVAILABLE, extra: [
            'expires_at' => now()->addDays(10),
        ]);
        $this->actingAs($soloOwner)->get(route('settings.account'))
            ->assertOk()
            ->assertSee('You have 1 reward still outstanding. It stays good until 30 days after you end, or until its own use by date if that comes first. Any that are not claimed by then run out.');

        $pair = World::business();
        $pairOwner = World::owner($pair);
        $this->rewardInState($pair, Reward::STATE_AVAILABLE, extra: [
            'expires_at' => now()->addDays(10),
        ]);
        $this->rewardInState($pair, Reward::STATE_AVAILABLE);
        $this->actingAs($pairOwner)->get(route('settings.account'))
            ->assertOk()
            ->assertSee('You have 2 rewards still outstanding. They stay good until 30 days after you end, or until their own use by date if that comes first. Any that are not claimed by then run out.');

        $later = World::business();
        $laterOwner = World::owner($later);
        $this->rewardInState($later, Reward::STATE_AVAILABLE, extra: [
            'expires_at' => now()->addDays(60),
        ]);
        $this->actingAs($laterOwner)->get(route('settings.account'))
            ->assertOk()
            ->assertSee('You have 1 reward still outstanding. It stays good until 30 days after you end. Any that are not claimed by then run out.')
            ->assertDontSee('own use by date if that comes first. Any that are not claimed');
    }
}
