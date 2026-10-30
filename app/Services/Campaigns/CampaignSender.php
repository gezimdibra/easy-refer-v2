<?php

namespace App\Services\Campaigns;

use App\Jobs\SendCampaignRecipient;
use App\Models\Business;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Services\Billing\SmsMetering;
use App\Services\Campaigns\CampaignPrompts;
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
    public function figures(Business $business, string $ownerBody, ?string $segment = null): array
    {
        $rendered = MessageCopy::campaign($business, $ownerBody);
        $segments = SmsSegments::count($rendered);
        $perRecipient = SmsMetering::priceForSegments($segments);
        $recipients = CampaignAudience::forBusiness($business, $segment)['memberships']->count();
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
     * The figures for a recognition draft (ID-02): computed on
     * the FULLY RENDERED recognition text (prefix + body + the
     * TR-07 link appendage + STOP suffix) for exactly the one
     * locked recipient, who counts only while they are inside
     * the campaign audience read (consent, STOP, and the F1
     * recency limbs all read through that one object).
     *
     * @return array{rendered_body: string, segments: int, encoding: string, per_recipient_cents: int, recipients: int, total_cents: int, fits_today: int, metered_cents: int}
     */
    public function recognitionFigures(SmsCampaign $campaign): array
    {
        $business = $campaign->business;
        $recipient = $campaign->recipientMembership;
        $rendered = $recipient !== null
            ? MessageCopy::recognition($business, $campaign->body, $recipient)
            : MessageCopy::campaign($business, $campaign->body);
        $segments = SmsSegments::count($rendered);
        $perRecipient = SmsMetering::priceForSegments($segments);
        $sendable = $recipient !== null
            && CampaignAudience::forBusiness($business)['memberships']->contains('id', $recipient->id);
        $recipients = $sendable ? 1 : 0;
        $fits = max(0, min($recipients, (int) config('campaigns.daily_cap') - $this->usedToday($business)));

        return [
            'rendered_body' => $rendered,
            'segments' => $segments,
            'encoding' => SmsSegments::encoding($rendered),
            'per_recipient_cents' => $perRecipient,
            'recipients' => $recipients,
            'total_cents' => $perRecipient * $recipients,
            'fits_today' => $fits,
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
        if ($campaign->isRecognition()) {
            $figures = $this->recognitionFigures($campaign);
            $campaign->forceFill([
                'recipient_count' => $figures['recipients'],
                'segment_count' => $figures['segments'],
                'metered_cents' => $figures['metered_cents'],
            ])->save();

            return;
        }

        // The draft's stored segment is the only segment any
        // surface may read (ID-03 section 1.5): figures are
        // never widened from request data between confirm and
        // send, and segment drift moves the figures and
        // bounces the send, exactly like any other movement.
        $figures = $this->figures($campaign->business, $campaign->body, $campaign->audience_segment);

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
        if ($campaign->isRecognition()) {
            return $this->sendRecognition($campaign);
        }

        if ($campaign->status !== SmsCampaign::STATUS_DRAFT) {
            return ['outcome' => 'already'];
        }

        // F2 (Amendment 45): a closed business's program has
        // ended; there is no campaign path. The send refuses
        // before any audience read or row creation, and the
        // draft stays a draft.
        if ($campaign->business->isClosed()) {
            return ['outcome' => 'blocked_closed'];
        }

        // ID-03 (Amendment 46, design 4.2): a SEGMENT draft's
        // body promises a reward whose truth IS the flow
        // state, so the segment send path re-checks it: while
        // the flow is inactive the send does not proceed and
        // the draft stays saved. Whole-list drafts keep the
        // A4 posture (warn, may send); this gate is the
        // segment path's own, as designed.
        if ($campaign->audience_segment !== null && ! $campaign->business->flowIsActive()) {
            return ['outcome' => 'blocked_flow'];
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
            $audience = CampaignAudience::forBusiness($business, $campaign->audience_segment, $campaign->winback_exempt_campaign_id);
            $eligible = $audience['memberships'];
            $figures = $this->figures($business, $campaign->body, $campaign->audience_segment);

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
                // F1 (Amendment 45): the recency skips frozen
                // at send, from this same in-transaction
                // audience read (the not_sent_count precedent).
                'recent_spacing_skipped_count' => $audience['exclusions']['recently_texted_spacing'],
                'recent_cap_skipped_count' => $audience['exclusions']['recently_texted_cap'],
                // ID-03: the win-back cooldown skips frozen at
                // send, from this same audience read.
                'winback_cooldown_skipped_count' => $audience['exclusions']['winback_cooldown'] ?? 0,
                'status' => SmsCampaign::STATUS_SENDING,
                'sent_at' => now(),
            ])->save();

            $outcome = 'sent';
        });

        if ($outcome !== 'sent') {
            return ['outcome' => $outcome];
        }

        CampaignPrompts::noteSent($campaign->fresh() ?? $campaign);

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
     * The recognition send (ID-02, design section 3.5): the
     * standings are recomputed inside the locked transaction
     * and never trusted from the card or the confirm. Outcomes:
     * 'sent'; 'bounced' (standings moved, body uncustomised:
     * the body is regenerated from the current template,
     * re-stamped, and nothing sends until the owner re-sees
     * it); 'recognition_changed' (standings moved and the body
     * is the owner's own words: never silently rewritten, the
     * send is blocked until re-confirmed); 'recognition_ineligible'
     * (the leader dropped below the floor or lost sendability:
     * refused outright, TR-09d); 'recognition_stale' (the
     * standings month is no longer the current window month);
     * 'recognition_recent' (F1 excludes THE LEADER right now
     * — the leader's own id in the audience read's recency
     * skips; no override exists; if the window closes first,
     * the month is simply skipped); 'blocked_flow' / 'blocked_closed'
     * (the prompted-path flow re-check); 'paused'; 'already'.
     *
     * @return array{outcome: string}
     */
    public function sendRecognition(SmsCampaign $campaign): array
    {
        if ($campaign->status !== SmsCampaign::STATUS_DRAFT) {
            return ['outcome' => 'already'];
        }

        if ($campaign->business->isClosed()) {
            return ['outcome' => 'blocked_closed'];
        }

        if (! $campaign->business->flowIsActive()) {
            return ['outcome' => 'blocked_flow'];
        }

        $outcome = 'already';
        $queuedIds = [];

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

            if (TopReferrerStandings::currentStandingsMonth($business) !== $campaign->standings_month) {
                $outcome = 'recognition_stale';

                return;
            }

            $recipientId = (int) $campaign->recipient_membership_id;
            $fingerprint = TopReferrerStandings::fingerprintFor($business, (string) $campaign->standings_month, $recipientId);

            if ($fingerprint === null) {
                // Cause attribution is LEADER-SCOPED (two-key
                // Condition 2, 2026-10-28): recognition_recent
                // is returned ONLY when the locked membership's
                // own id is in the audience read's recency
                // skips (spacing or cap). Every other send-time
                // ineligibility — STOP, consent loss, removal,
                // below the floor, no longer the leader — takes
                // TR-09d's flash via recognition_ineligible.
                // The standings alone cannot separate the
                // causes (countsForMonth is audience-blind), so
                // the audience read is the only cause source.
                $audience = CampaignAudience::forBusiness($business);
                $recencyIds = array_merge(
                    $audience['recency_skipped_ids']['spacing'] ?? [],
                    $audience['recency_skipped_ids']['cap'] ?? [],
                );

                $outcome = in_array($recipientId, $recencyIds, true)
                    ? 'recognition_recent'
                    : 'recognition_ineligible';

                return;
            }

            if ($fingerprint['count'] !== (int) $campaign->standings_count
                || $fingerprint['form'] !== $campaign->standings_form) {
                if ($campaign->body_customized) {
                    $outcome = 'recognition_changed';

                    return;
                }

                $leader = $campaign->recipientMembership;
                $campaign->forceFill([
                    'body' => TopReferrerStandings::templateBody($business, $leader, (string) $campaign->standings_month, $fingerprint['count'], $fingerprint['form']),
                    'standings_count' => $fingerprint['count'],
                    'standings_form' => $fingerprint['form'],
                ])->save();
                $this->stampFigures($campaign);
                $outcome = 'bounced';

                return;
            }

            $audience = CampaignAudience::forBusiness($business);
            $eligible = $audience['memberships']->filter(fn ($m) => $m->id === $recipientId)->values();

            if ($eligible->count() + $this->platformUsedToday() > (int) config('campaigns.platform_daily_ceiling')) {
                $outcome = 'paused';

                return;
            }

            $figures = $this->recognitionFigures($campaign);

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
                'recent_spacing_skipped_count' => $audience['exclusions']['recently_texted_spacing'],
                'recent_cap_skipped_count' => $audience['exclusions']['recently_texted_cap'],
                'status' => SmsCampaign::STATUS_SENDING,
                'sent_at' => now(),
            ])->save();

            $outcome = 'sent';
        });

        if ($outcome !== 'sent') {
            return ['outcome' => $outcome];
        }

        foreach ($queuedIds as $recipientId) {
            SendCampaignRecipient::dispatch($recipientId);
        }

        if ($queuedIds === []) {
            $this->finalizeIfComplete($campaign->fresh());
        }

        return ['outcome' => 'sent'];
    }

    /**
     * The RK-02 resend send: a fresh draft carrying a sent
     * campaign's body, sent to a RESTRICTED audience. The given
     * membership ids (the source campaign's failed recipients)
     * only narrow who is considered; eligibility is re-derived
     * here, at send time, through CampaignAudience, so a number
     * that opted out, lost consent, or was removed since the
     * source send is excluded even though it sits in the source's
     * stored recipient list. The figures are computed against
     * the restricted set (the results page the owner clicked
     * from IS the confirmation, so there is no confirm-screen
     * bounce), the platform ceiling and the daily cap bind
     * exactly as in send(), and the recipient rows are created
     * inside the same FB-01 locked transaction.
     *
     * @param  list<int>  $membershipIds
     * @return array{outcome: string, recipients: int}
     */
    public function sendToMembers(SmsCampaign $campaign, array $membershipIds): array
    {
        if ($campaign->status !== SmsCampaign::STATUS_DRAFT) {
            return ['outcome' => 'already', 'recipients' => 0];
        }

        // F2 (Amendment 45): the resend path is a send too; a
        // closed business is blocked here exactly as in send().
        if ($campaign->business->isClosed()) {
            return ['outcome' => 'blocked_closed', 'recipients' => 0];
        }

        // ID-03: the segment resend inherits the segment
        // flow gate exactly as send() applies it.
        if ($campaign->audience_segment !== null && ! $campaign->business->flowIsActive()) {
            return ['outcome' => 'blocked_flow', 'recipients' => 0];
        }

        // ID-02: a recognition resend can only ever re-reach
        // its one locked recipient, whatever id list the
        // caller passes.
        if ($campaign->isRecognition()) {
            $membershipIds = array_values(array_intersect($membershipIds, [(int) $campaign->recipient_membership_id]));
        }

        $pace = max(0, (int) config('campaigns.send_interval_seconds'));
        $outcome = 'already';
        $queuedIds = [];
        $recipientCount = 0;

        DB::transaction(function () use ($campaign, $membershipIds, &$outcome, &$queuedIds, &$recipientCount) {
            $campaign = SmsCampaign::query()
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->first();

            if ($campaign === null || $campaign->status !== SmsCampaign::STATUS_DRAFT) {
                $outcome = 'already';

                return;
            }

            $business = $campaign->business;
            // ID-03: the resend of a segment campaign re-derives
            // through the SEGMENT audience (never widened), with
            // the source campaign exempted from the win-back
            // cooldown: its own failed recipients are resendable
            // from its results page (the own-send exception).
            $resendAudience = CampaignAudience::forBusiness($business, $campaign->audience_segment, $campaign->winback_exempt_campaign_id);
            $eligible = $resendAudience['memberships']
                ->filter(fn ($membership) => in_array($membership->id, $membershipIds, true))
                ->values();
            // F1: the resend inherits the cap through the same
            // audience read; the stamps count only the members
            // this resend actually asked about.
            $resendSpacingSkips = count(array_intersect($resendAudience['recency_skipped_ids']['spacing'], $membershipIds));
            $resendCapSkips = count(array_intersect($resendAudience['recency_skipped_ids']['cap'], $membershipIds));

            if ($eligible->count() + $this->platformUsedToday() > (int) config('campaigns.platform_daily_ceiling')) {
                $outcome = 'paused';

                return;
            }

            $rendered = $campaign->isRecognition() && $campaign->recipientMembership !== null
                ? MessageCopy::recognition($business, $campaign->body, $campaign->recipientMembership)
                : MessageCopy::campaign($business, $campaign->body);
            $segments = SmsSegments::count($rendered);
            $perRecipient = SmsMetering::priceForSegments($segments);
            $fits = max(0, min(
                $eligible->count(),
                (int) config('campaigns.daily_cap') - $this->usedToday($business)
            ));

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
                    'segments' => $segments,
                    'amount_cents' => $perRecipient,
                ]);

                if ($sending) {
                    $queuedIds[] = $row->id;
                }
            }

            $campaign->forceFill([
                'recipient_count' => $eligible->count(),
                'segment_count' => $segments,
                'metered_cents' => $perRecipient * $fits,
                'not_sent_count' => $eligible->count() - $fits,
                'recent_spacing_skipped_count' => $resendSpacingSkips,
                'recent_cap_skipped_count' => $resendCapSkips,
                'winback_cooldown_skipped_count' => count(array_intersect($resendAudience['winback_skipped_ids'] ?? [], $membershipIds)),
                'status' => SmsCampaign::STATUS_SENDING,
                'sent_at' => now(),
            ])->save();

            $recipientCount = $eligible->count();
            $outcome = 'sent';
        });

        if ($outcome !== 'sent') {
            return ['outcome' => $outcome, 'recipients' => 0];
        }

        CampaignPrompts::noteSent($campaign->fresh() ?? $campaign);

        foreach ($queuedIds as $index => $recipientId) {
            $job = SendCampaignRecipient::dispatch($recipientId);
            if ($pace > 0 && $index > 0) {
                $job->delay(now()->addSeconds($index * $pace));
            }
        }

        if ($queuedIds === []) {
            $this->finalizeIfComplete($campaign->fresh());
        }

        return ['outcome' => 'sent', 'recipients' => $recipientCount];
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
            // FE-CAMP-01: the stamped cost settles to the
            // attempted truth at completion. The send-time
            // stamp counted everyone who fit the cap; a
            // recipient G-14-skipped at job time was inside
            // that figure but was never attempted, so it must
            // not survive in the one cost figure the index
            // shows. The attempted sum is the same definition
            // the detail page renders and the monthly charger
            // bills on, so all three read one number. Drafts
            // and in-flight campaigns keep the send-time stamp
            // (an estimate / in-progress figure).
            'metered_cents' => (int) $campaign->recipients()
                ->whereNotNull('sms_message_id')
                ->sum('amount_cents'),
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
