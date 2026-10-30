<?php

namespace App\Services\Campaigns;

use App\Jobs\SendCampaignRecipient;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use Illuminate\Support\Facades\Log;

/**
 * The RR-01 recovery sweep: CampaignSender dispatches the
 * recipient jobs after its send transaction commits, so a
 * process dying in that window leaves recipient rows in
 * `queued` with no job ever dispatched — a paid campaign that
 * silently never sends (under-send only; the rows were never
 * attempted, so nothing was billed on them either).
 *
 * A row is a sweep target only when all of these hold:
 * - its campaign is in a sent/sending state (the send
 *   transaction committed; drafts never queued rows, and
 *   terminal campaigns are not the sweeper's business);
 * - the row is still `queued`;
 * - the row was never attempted (no sms_messages evidence
 *   link). An attempted row that is still queued is a job
 *   that ran, failed at the provider, and released itself
 *   for retry — its retry is already scheduled and the
 *   sweeper stays out of its way;
 * - the row is older than the grace window (below).
 *
 * Safety rests on the recipient job's per-row idempotency,
 * proven in StaleQueuedCampaignSweeperTest: the job returns
 * immediately unless the row is still `queued`, and billing
 * claims a row only through its single evidence row, which
 * the job creates at most once. A re-dispatch aimed at a
 * recipient who was in fact already sent can therefore never
 * produce a second SMS or a second metered cent.
 */
class StaleQueuedCampaignSweeper
{
    /**
     * The grace window, in minutes. Dispatch in CampaignSender
     * is synchronous right after the commit, so a healthy row
     * is dispatched within the same request that created it.
     * What keeps a healthy row `queued` afterwards is only the
     * pacing tail: the last recipient's job is delayed by
     * (audience − 1) × send_interval_seconds, and the audience
     * is bounded by the daily cap — at the shipped defaults
     * (cap 1000, pace 1s) that is ≈17 minutes before the job
     * is even available, plus the scheduler-driven queue
     * worker's one-minute cadence. 30 minutes clears that
     * worst case with headroom while still recovering a
     * crashed send on the sweeper's hourly rhythm. If the
     * pace or the cap is raised in config, raise the window
     * with it: grace must exceed (daily_cap − 1) ×
     * send_interval_seconds.
     */
    public const DEFAULT_GRACE_MINUTES = 30;

    /**
     * @return array{dispatched: int, campaigns: int}
     */
    public function sweep(int $olderThanMinutes = self::DEFAULT_GRACE_MINUTES): array
    {
        $pace = max(0, (int) config('campaigns.send_interval_seconds'));
        $dispatched = 0;
        $campaigns = [];
        $paceIndex = [];

        SmsCampaignRecipient::query()
            ->where('delivery_state', SmsCampaignRecipient::STATE_QUEUED)
            ->whereNull('sms_message_id')
            ->where('created_at', '<=', now()->subMinutes($olderThanMinutes))
            ->whereHas('campaign', fn ($query) => $query->whereIn('status', [
                SmsCampaign::STATUS_SENDING,
                SmsCampaign::STATUS_SENT,
            ]))
            ->orderBy('id')
            ->chunkById(200, function ($recipients) use (&$dispatched, &$campaigns, &$paceIndex, $pace) {
                foreach ($recipients as $recipient) {
                    $job = SendCampaignRecipient::dispatch($recipient->id);

                    // Mirror the sender's pacing per campaign,
                    // so a large recovered campaign does not
                    // hit the provider pool all at once.
                    $index = $paceIndex[$recipient->campaign_id] ?? 0;
                    if ($pace > 0 && $index > 0) {
                        $job->delay(now()->addSeconds($index * $pace));
                    }
                    $paceIndex[$recipient->campaign_id] = $index + 1;

                    $campaigns[$recipient->campaign_id] = true;
                    $dispatched++;
                }
            });

        $summary = ['dispatched' => $dispatched, 'campaigns' => count($campaigns)];

        if ($dispatched > 0) {
            Log::info('Stale-queued campaign sweeper re-dispatched stranded recipients.', $summary);
        }

        return $summary;
    }
}
