<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Models\Reward;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Services\Referral\MessageCopy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The referrer reward-ready SMS (spec Section 3 Step 11), sent
 * after the redemption transaction commits. The reward's
 * notification_state is the record: queued, sent, failed (retried
 * by the queue, never silently dropped, EC-23), or suppressed by a
 * platform opt-out (SMS-05). A send that keeps failing stays
 * visible as failed on the reward row after the final attempt.
 */
class SendRewardReadySms implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $rewardId) {}

    public function handle(SmsGateway $sms): void
    {
        $reward = Reward::query()->with(['business', 'membership.customer'])->find($this->rewardId);

        if ($reward === null || $reward->notification_state === 'sent') {
            return;
        }

        // FH-OB1 (outbound truth sweep OB-01): this text asserts
        // the reward "is ready to be claimed", so claimability is
        // re-checked at SEND time, not dispatch time. Closure
        // expiry and vesting are applied first (the same on-read
        // transitions every claim surface applies), then:
        //  - denied / expired / already claimed are terminal: the
        //    text can never become true, so the notification is
        //    marked suppressed and nothing sends;
        //  - held (review hold or number-link cooling) or still
        //    vesting are transient: nothing sends, and the
        //    notification state is left untouched so the release
        //    path / due sweep can still send when the reward is
        //    genuinely claimable.
        // Two-key fix F2: vesting runs FIRST, in the claimPage /
        // staff-page order. Time expiry guards on state
        // available, so running it before vesting could never
        // see a both-due pending_vesting row (vesting elapsed
        // AND use-by date past) and the job sent a "ready" text
        // carrying a past date.
        $reward->applyVesting();
        $reward->applyClosureExpiry();
        // M-4: time expiry joins the pre-check, so a queued text
        // can never fire across the reward's own use-by boundary
        // (a time-expired reward is terminal below, exactly like
        // a closure-expired one).
        $reward->applyTimeExpiry();

        if ($reward->state !== Reward::STATE_AVAILABLE || $reward->hold) {
            if (in_array($reward->state, [Reward::STATE_DENIED, Reward::STATE_EXPIRED, Reward::STATE_CLAIMED], true)) {
                $reward->forceFill(['notification_state' => 'suppressed'])->save();
            }

            return;
        }

        $customer = $reward->membership->customer;
        $business = $reward->business;

        if (SmsOptOut::optedOut($customer->phone_e164)) {
            $reward->forceFill(['notification_state' => 'suppressed'])->save();

            return;
        }

        $claimUrl = route('refer.claim.page', ['token' => $reward->claim_token_encrypted]);
        // M-4 [M4-SMS-02]: the base body carries the reward's own
        // use-by date (business-local), before any appendage.
        $goodUntilDate = $reward->expires_at !== null
            ? $business->localTime($reward->expires_at)->format('M j, Y')
            : null;
        $body = MessageCopy::rewardReady($business, $reward->reward_text_snapshot, $claimUrl, $goodUntilDate);

        // ID-07 (Amendment 51): the reshare appendage, decided
        // HERE at send time: after the FH-OB1 claimability and
        // opt-out checks above have passed, so a text that does
        // not send never carries one, and no caller (sweep,
        // release, owner resend) can bypass the gate. Any gate
        // failure sends the base text alone, byte-identical.
        $shareToken = $reward->membership?->plainShareToken();
        $reshareBody = $this->reshareBody($reward, $business, $customer, $body, $shareToken);
        $templateKey = 'reward_ready';

        if ($reshareBody !== null) {
            $body = $reshareBody;
            // RR-M1: the stamp is the exposure flag for MEASURE,
            // the RR-CAP ledger, and the audit enumeration for
            // the classification ruling's reversal path.
            $templateKey = 'reward_ready_reshare';
        }

        $renderedBody = \App\Support\OpaqueToken::redact($body, (string) $reward->claim_token_encrypted);

        if ($templateKey === 'reward_ready_reshare' && $shareToken !== null) {
            // F-09 hygiene: the stored evidence redacts BOTH
            // live tokens the composed body carries.
            $renderedBody = \App\Support\OpaqueToken::redact($renderedBody, $shareToken);
        }

        $record = SmsMessage::create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'rendered_body' => $renderedBody,
            'purpose' => 'reward_ready',
            'template_key' => $templateKey,
            'delivery_state' => 'queued',
            'consent_basis' => 'transactional',
            'opt_out_state_at_send' => false,
        ]);

        $sid = $sms->send($customer->phone_e164, $body);

        if ($sid === null) {
            $record->forceFill(['delivery_state' => 'failed'])->save();
            $reward->forceFill(['notification_state' => 'failed'])->save();

            if ($this->attempts() < $this->tries) {
                $this->release(60 * $this->attempts());
            }

            return;
        }

        $record->forceFill(['twilio_message_sid' => $sid, 'delivery_state' => 'sent'])->save();
        $reward->forceFill(['notification_state' => 'sent'])->save();
    }

    /**
     * ID-07: compose the body WITH the reshare appendage, or
     * null when any conjunct fails (the caller then sends the
     * base text alone). All conjuncts read the world as it is
     * at send time:
     *  - RR-GATE 1, flow live: Business::flowIsActive(): status
     *    active, friend reward text set, billing active or
     *    past-due INSIDE grace. Paused past grace, closed, and
     *    textless businesses fail: a friend tapping the link
     *    would land on the not_active page. (The BASE text does
     *    NOT share this gate: claimability survives a pause.)
     *  - RR-GATE 2, link live (TS3-12): the membership is not
     *    removed and its share token is active and present.
     *    A removed member's token is revoked, so the appendage
     *    would be false for them even though their earned
     *    reward's base text stays true (the claim path has no
     *    removal gate; pass Section 6 V11). The base send
     *    itself is deliberately NOT suppressed here: that
     *    question is the truth rotation's (pass Section 11).
     *  - RR-CAP: at most one appendage-carrying send per
     *    (business, customer) in any rolling 7 days, counted
     *    on SENT RR-M1 rows only: a failed delivery made no
     *    ask and consumes nothing.
     *  - RR-SEG: the composed body may add at most one segment
     *    over the base, counted on the real strings.
     */
    private function reshareBody(Reward $reward, \App\Models\Business $business, \App\Models\Customer $customer, string $baseBody, ?string $shareToken): ?string
    {
        if (! $business->flowIsActive()) {
            return null;
        }

        $membership = $reward->membership;

        if ($membership === null
            || $membership->removed_at !== null
            || $membership->share_token_state !== 'active'
            || $shareToken === null
            || $shareToken === '') {
            return null;
        }

        $recentAppendage = SmsMessage::query()
            ->where('business_id', $business->id)
            ->where('customer_id', $customer->id)
            ->where('purpose', 'reward_ready')
            ->where('template_key', 'reward_ready_reshare')
            ->where('delivery_state', 'sent')
            ->where('created_at', '>', now()->subDays(7))
            ->exists();

        if ($recentAppendage) {
            return null;
        }

        // The came-as-a-friend cohort (ID-08 Section 7.2
        // provenance join): a redeemed referral exists at this
        // business naming this customer as the referred friend.
        $cameAsFriend = \App\Models\Referral::query()
            ->where('business_id', $business->id)
            ->where('referred_customer_id', $customer->id)
            ->where('state', \App\Models\Referral::STATE_REDEEMED)
            ->exists();

        $shareUrl = route('refer.friend', ['token' => $shareToken]);
        $composed = $baseBody.MessageCopy::rewardReadyReshareAppendage($shareUrl, $cameAsFriend);

        if (\App\Support\SmsSegments::count($composed) > \App\Support\SmsSegments::count($baseBody) + 1) {
            return null;
        }

        return $composed;
    }
}
