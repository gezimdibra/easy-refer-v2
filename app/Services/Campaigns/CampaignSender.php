<?php

namespace App\Services\Campaigns;

use App\Jobs\SendCampaignRecipient;
use App\Models\Business;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Services\Billing\SmsMetering;
use App\Services\Referral\MessageCopy;
use App\Support\SmsSegments;
use Illuminate\Support\Facades\DB;

/**
 * The campaign send sequence (spec Sections 10.3, 10.4): figures
 * are computed server-side from the eligibility query and the
 * final rendered body, never from request data. The send itself
 * recomputes from ground truth, bounces to the confirm screen
 * when the numbers moved since the owner approved them, applies
 * the daily cap as recorded not-sent rows, and dispatches one
 * paced job per recipient.
 */
class CampaignSender
{
    /**
     * The figures every surface shows: the estimate endpoint,
     * the compose page, the confirm screen, and the send's
     * recompute all read this one computation.
     *
     * @return array{
     *     rendered_body: string,
     *     segments: int,
     *     encoding: string,
     *     per_recipient_cents: int,
     *     recipients: int,
     *     total_cents: int,
     *     fits_today: int,
     *     metered_cents: int
     * }
     */
    public function figures(Business $business, string $ownerBody): array
    {
        $rendered = MessageCopy::campaign($business, $ownerBody);
        $segments = SmsSegments::count($rendered);
        $perRecipient = SmsMetering::priceForSegments($segments);
        $recipients = CampaignAudience::forBusiness($business)['memberships']->count();
        $fits = max(0, min($recipients, (int) config('campaigns.daily_cap') - $this->usedToday($business)));

        return [
            'rendered_body' => $rendered,
            'segments' => $segments,
            'encoding' => SmsSegments::encoding($rendered),
            'per_recipient_cents' => $perRecipient,
            'recipients' => $recipients,
            'total_cents' => $perRecipient * $recipients,
            'fits_today' => $fits,
            // What sending now would meter: the capped audience
            // is what the owner is actually approving.
            'metered_cents' => $perRecipient * $fits,
        ];
    }

    /**
     * Refresh a draft's stored figures from ground truth (the
     * confirm render does this, so the send's comparison is
     * against exactly what the owner last saw).
     */
    public function stampFigures(SmsCampaign $campaign): void
    {
        $figures = $this->figures($campaign->business, $campaign->body);

        $campaign->forceFill([
            'recipient_count' => $figures['recipients'],
            'segment_count' => $figures['segments'],
            'metered_cents' => $figures['metered_cents'],
        ])->save();
    }

    /**
     * The send POST sequence. Outcomes: 'sent' (recipient rows
     * created and jobs dispatched), 'bounced' (the numbers moved
     * since confirm; the draft's figures are updated and nothing
     * was queued), 'paused' (the platform ceiling is reached;
     * the campaign stays a draft), 'already' (not a draft: a
     * double submit, handled as a no-op by the caller).
     *
     * @return array{outcome: string}
     */
    public function send(SmsCampaign $campaign): array
    {
        if ($campaign->status !== SmsCampaign::STATUS_DRAFT) {
            return ['outcome' => 'already'];
        }

        $pace = max(0, (int) config('campaigns.send_interval_seconds'));
        $outcome = 'already';
        $queuedIds = [];

        // The whole sequence runs in one transaction on a locked,
        // freshly read campaign row (FB-01): two racing submits
        // each hold an instance that still reads "draft", so the
        // guard above cannot be the only check. Under the lock the
        // second sender waits, its re-read then shows the first
        // sender's flip, and its send is a no-op. Recipient rows
        // are created exactly once, with the status flip, inside
        // this same transaction (design Section 1.3).
        DB::transaction(function () use ($campaign, &$outcome, &$queuedIds) {
            $campaign = SmsCampaign::query()
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->first();

            if ($campaign === null || $campaign->status !== SmsCampaign::STATUS_DRAFT) {
                $outcome = 'already';

                return;
            }

            $business = $campaign->business;
            $audience = CampaignAudience::forBusiness($business);
            $eligible = $audience['memberships'];
            $figures = $this->figures($business, $campaign->body);

            if ($eligible->count() + $this->platformUsedToday() > (int) config('campaigns.platform_daily_ceiling')) {
                $outcome = 'paused';

                return;
            }

            if ($campaign->recipient_count !== $figures['recipients']
                || $campaign->segment_count !== $figures['segments']
                || $campaign->metered_cents !== $figures['metered_cents']) {
                $this->stampFigures($campaign);
                $outcome = 'bounced';

                return;
            }

            $fits = $figures['fits_today'];

            foreach ($eligible as $index => $membership) {
                $sending = $index < $fits;

                $row = SmsCampaignRecipient::create([
                    'campaign_id' => $campaign->id,
                    'membership_id' => $membership->id,
                    'customer_id' => $membership->customer_id,
                    'phone_e164' => $membership->customer->phone_e164,
                    'delivery_state' => $sending
                        ? SmsCampaignRecipient::STATE_QUEUED
                        : SmsCampaignRecipient::STATE_NOT_SENT,
                    'skip_reason' => $sending ? null : SmsCampaignRecipient::SKIP_DAILY_CAP,
                    'segments' => $figures['segments'],
                    'amount_cents' => $figures['per_recipient_cents'],
                ]);

                if ($sending) {
                    $queuedIds[] = $row->id;
                }
            }

            $campaign->forceFill([
                'recipient_count' => $figures['recipients'],
                'segment_count' => $figures['segments'],
                'metered_cents' => $figures['metered_cents'],
                'not_sent_count' => $figures['recipients'] - $fits,
                'status' => SmsCampaign::STATUS_SENDING,
                'sent_at' => now(),
            ])->save();

            $outcome = 'sent';
        });

        if ($outcome !== 'sent') {
            return ['outcome' => $outcome];
        }

        foreach ($queuedIds as $index => $recipientId) {
            $job = SendCampaignRecipient::dispatch($recipientId);
            if ($pace > 0 && $index > 0) {
                $job->delay(now()->addSeconds($index * $pace));
            }
        }

        if ($queuedIds === []) {
            $this->finalizeIfComplete($campaign->fresh());
        }

        return ['outcome' => 'sent'];
    }

    /**
     * Campaign completion: when no recipient rows remain in
     * queued or sent, the campaign is done: `sent`, or `failed`
     * when rows were expected and not one send was attempted.
     * Called at the end of every recipient job and every status
     * callback that advances a recipient.
     */
    public function finalizeIfComplete(SmsCampaign $campaign): void
    {
        if ($campaign->status !== SmsCampaign::STATUS_SENDING) {
            return;
        }

        $open = $campaign->recipients()
            ->whereIn('delivery_state', [SmsCampaignRecipient::STATE_QUEUED, SmsCampaignRecipient::STATE_SENT])
            ->count();

        if ($open > 0) {
            return;
        }

        $attempted = $campaign->recipients()->whereNotNull('sms_message_id')->count();
        $expected = $campaign->recipients()->count();

        $campaign->forceFill([
            'status' => $attempted > 0 || $expected === 0
                ? SmsCampaign::STATUS_SENT
                : SmsCampaign::STATUS_FAILED,
        ])->save();
    }

    /**
     * This business's campaign rows created today that count
     * against the daily cap (every state but not_sent: a skipped
     * row was queued against the cap before its re-check).
     */
    public function usedToday(Business $business): int
    {
        return SmsCampaignRecipient::query()
            ->whereHas('campaign', fn ($query) => $query->where('business_id', $business->id))
            ->whereDate('created_at', now()->toDateString())
            ->where('delivery_state', '!=', SmsCampaignRecipient::STATE_NOT_SENT)
            ->count();
    }

    /**
     * The platform-wide equivalent (threat G-03 ceiling).
     */
    public function platformUsedToday(): int
    {
        return SmsCampaignRecipient::query()
            ->whereDate('created_at', now()->toDateString())
            ->where('delivery_state', '!=', SmsCampaignRecipient::STATE_NOT_SENT)
            ->count();
    }
}
