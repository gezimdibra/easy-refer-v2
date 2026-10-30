<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Jobs\SendCampaignRecipient;
use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RR-01: the stale-queued campaign sweeper.
 *
 * CampaignSender dispatches recipient jobs AFTER its send
 * transaction commits. If the process dies in that window the
 * recipient rows stay `queued` with no job ever dispatched: a
 * paid campaign silently never sends (under-send only). The
 * sweeper re-dispatches the existing recipient job for rows
 * that are still queued past the grace window.
 *
 * The precondition the whole design rests on is proven first,
 * in this file: SendCampaignRecipient is idempotent per row.
 * Its first guard returns unless the row is still `queued`,
 * and billing claims a row only through its single
 * sms_messages evidence row, which the job creates at most
 * once. A re-dispatch aimed at a recipient who was in fact
 * already sent therefore cannot produce a second SMS or a
 * second metered cent.
 */
class StaleQueuedCampaignSweeperTest extends TestCase
{
    use RefreshDatabase;

    private FakeMarketingSmsGateway $gateway;

    private int $phoneSequence = 5000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeMarketingSmsGateway;
        $this->app->instance(MarketingSmsGateway::class, $this->gateway);
    }

    private function member(Business $business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    private function campaign(Business $business, string $status): SmsCampaign
    {
        return SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'recipient_count' => 0,
            'segment_count' => 1,
            'metered_cents' => 0,
            'status' => $status,
            'created_by' => World::owner($business)->id,
            'sent_at' => $status === SmsCampaign::STATUS_DRAFT ? null : now()->subHours(2),
        ]);
    }

    private function recipient(SmsCampaign $campaign, Membership $membership, string $state, int $ageMinutes, bool $attempted = false): SmsCampaignRecipient
    {
        $row = SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $membership->id,
            'customer_id' => $membership->customer_id,
            'phone_e164' => $membership->customer->phone_e164,
            'delivery_state' => $state,
            'segments' => 1,
            'amount_cents' => 2,
        ]);

        if ($attempted) {
            $message = SmsMessage::create([
                'business_id' => $campaign->business_id,
                'customer_id' => $row->customer_id,
                'phone_e164' => $row->phone_e164,
                'purpose' => 'campaign',
                'messaging_service' => 'marketing',
                'template_key' => 'campaign',
                'rendered_body' => 'body',
                'delivery_state' => 'failed',
                'consent_basis' => 'customer_checkbox',
                'opt_out_state_at_send' => false,
            ]);
            $row->forceFill(['sms_message_id' => $message->id])->save();
        }

        SmsCampaignRecipient::query()->whereKey($row->id)
            ->update(['created_at' => now()->subMinutes($ageMinutes)]);

        return $row->fresh();
    }

    // ---------------------------------------------------------------
    // The precondition: the recipient job is idempotent per row.
    // ---------------------------------------------------------------

    public function test_recipient_job_run_twice_for_one_row_sends_and_meters_exactly_once(): void
    {
        $business = World::business();
        $membership = $this->member($business);
        $campaign = $this->campaign($business, SmsCampaign::STATUS_SENDING);
        $row = $this->recipient($campaign, $membership, SmsCampaignRecipient::STATE_QUEUED, 120);

        $sender = app(CampaignSender::class);
        // Two job instances, both created before either runs:
        // the duplicate-dispatch shape the sweeper can produce.
        $first = new SendCampaignRecipient($row->id);
        $second = new SendCampaignRecipient($row->id);

        $first->handle($this->gateway, $sender);
        $second->handle($this->gateway, $sender);

        $this->assertCount(1, $this->gateway->sent);
        $this->assertSame(1, SmsMessage::query()->count());

        $fresh = $row->fresh();
        $this->assertSame(SmsCampaignRecipient::STATE_SENT, $fresh->delivery_state);
        $this->assertNotNull($fresh->sms_message_id);
        // The metered claim is unchanged: one row, one evidence
        // link, the queue-time amount untouched.
        $this->assertSame(2, $fresh->amount_cents);
        $this->assertNull($fresh->billing_record_id);
    }

    public function test_recipient_job_is_a_no_op_for_every_non_queued_state(): void
    {
        $business = World::business();
        $campaign = $this->campaign($business, SmsCampaign::STATUS_SENDING);
        $sender = app(CampaignSender::class);

        foreach ([
            SmsCampaignRecipient::STATE_SENT,
            SmsCampaignRecipient::STATE_DELIVERED,
            SmsCampaignRecipient::STATE_FAILED,
            SmsCampaignRecipient::STATE_UNDELIVERED,
            SmsCampaignRecipient::STATE_SKIPPED,
            SmsCampaignRecipient::STATE_NOT_SENT,
        ] as $state) {
            $row = $this->recipient($campaign, $this->member($business), $state, 120);
            (new SendCampaignRecipient($row->id))->handle($this->gateway, $sender);
            $this->assertSame($state, $row->fresh()->delivery_state);
        }

        $this->assertCount(0, $this->gateway->sent);
        $this->assertSame(0, SmsMessage::query()->count());
    }

    // ---------------------------------------------------------------
    // The sweeper.
    // ---------------------------------------------------------------

    public function test_sweeper_leaves_a_healthy_in_flight_campaign_inside_the_grace_window_untouched(): void
    {
        Queue::fake();
        $business = World::business();
        $campaign = $this->campaign($business, SmsCampaign::STATUS_SENDING);
        $this->recipient($campaign, $this->member($business), SmsCampaignRecipient::STATE_QUEUED, 5);
        $this->recipient($campaign, $this->member($business), SmsCampaignRecipient::STATE_QUEUED, 29);

        $this->artisan('campaigns:sweep-stale-queued')->assertExitCode(0);

        Queue::assertNotPushed(SendCampaignRecipient::class);
    }

    public function test_sweeper_older_than_option_moves_the_grace_window(): void
    {
        Queue::fake();
        $business = World::business();
        $campaign = $this->campaign($business, SmsCampaign::STATUS_SENDING);
        $this->recipient($campaign, $this->member($business), SmsCampaignRecipient::STATE_QUEUED, 10);

        $this->artisan('campaigns:sweep-stale-queued', ['--older-than' => 5])->assertExitCode(0);

        Queue::assertPushed(SendCampaignRecipient::class, 1);
    }

    public function test_sweeper_redispatches_only_the_stuck_rows_of_a_sending_campaign(): void
    {
        Queue::fake();
        $business = World::business();
        $campaign = $this->campaign($business, SmsCampaign::STATUS_SENDING);

        $stuckA = $this->recipient($campaign, $this->member($business), SmsCampaignRecipient::STATE_QUEUED, 120);
        $stuckB = $this->recipient($campaign, $this->member($business), SmsCampaignRecipient::STATE_QUEUED, 45);
        // Already sent: not queued, never a target.
        $this->recipient($campaign, $this->member($business), SmsCampaignRecipient::STATE_SENT, 120);
        // Queued but already attempted (its job ran, failed at
        // the provider, and released itself for retry): the
        // retry is already scheduled, the sweeper stays out.
        $this->recipient($campaign, $this->member($business), SmsCampaignRecipient::STATE_QUEUED, 120, attempted: true);
        // Recorded as not sent by the daily cap at queue time.
        $this->recipient($campaign, $this->member($business), SmsCampaignRecipient::STATE_NOT_SENT, 120);

        // Rows of campaigns that are not in a sent/sending
        // state are never the sweeper's business.
        $draft = $this->campaign($business, SmsCampaign::STATUS_DRAFT);
        $this->recipient($draft, $this->member($business), SmsCampaignRecipient::STATE_QUEUED, 120);
        $failed = $this->campaign($business, SmsCampaign::STATUS_FAILED);
        $this->recipient($failed, $this->member($business), SmsCampaignRecipient::STATE_QUEUED, 120);

        $this->artisan('campaigns:sweep-stale-queued')->assertExitCode(0);

        Queue::assertPushed(SendCampaignRecipient::class, 2);
        $ids = Queue::pushed(SendCampaignRecipient::class)
            ->map(fn (SendCampaignRecipient $job) => $job->recipientId)
            ->sort()->values()->all();
        $this->assertSame([$stuckA->id, $stuckB->id], $ids);
    }

    public function test_sweeper_second_run_after_its_jobs_execute_changes_nothing(): void
    {
        Queue::fake();
        $business = World::business();
        $campaign = $this->campaign($business, SmsCampaign::STATUS_SENDING);
        $this->recipient($campaign, $this->member($business), SmsCampaignRecipient::STATE_QUEUED, 120);
        $this->recipient($campaign, $this->member($business), SmsCampaignRecipient::STATE_QUEUED, 120);

        $this->artisan('campaigns:sweep-stale-queued')->assertExitCode(0);
        Queue::assertPushed(SendCampaignRecipient::class, 2);

        // The queue worker drains what the sweeper dispatched.
        $sender = app(CampaignSender::class);
        foreach (Queue::pushed(SendCampaignRecipient::class) as $job) {
            $job->handle($this->gateway, $sender);
        }
        $this->assertCount(2, $this->gateway->sent);

        // The delivery callbacks land and the campaign
        // finalizes, exactly as in the live flow.
        SmsCampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->update(['delivery_state' => SmsCampaignRecipient::STATE_DELIVERED]);
        $sender->finalizeIfComplete($campaign->fresh());
        $this->assertSame(SmsCampaign::STATUS_SENT, $campaign->fresh()->status);

        Queue::fake();
        $this->artisan('campaigns:sweep-stale-queued')->assertExitCode(0);

        Queue::assertNotPushed(SendCampaignRecipient::class);
        $this->assertCount(2, $this->gateway->sent);
        $this->assertSame(2, SmsMessage::query()->count());
    }

    public function test_sweeper_is_registered_on_the_schedule(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('campaigns:sweep-stale-queued')
            ->assertExitCode(0);
    }
}
