<?php

namespace App\Support;

use App\Models\Business;

/**
 * SK-6: the self-reported signup source ("how did you hear
 * about us"), its fixed option set, and the ONE resolution of
 * a business's signup channel for every readout.
 *
 * The channels are the ones the growth plan names
 * (growth-marketing-plan.md Sections 2/3/6): the founder
 * walk-in, a live shop's decal or poster (G3), an owner
 * telling another owner (ID-09), a claim card or link made
 * for the shop (G1), the demo (G6), finding it online, and
 * other. The street tag rides beside the answer because the
 * street-saturation metric (G2/G3) reads streets.
 *
 * PRECEDENCE RULE (binding): a claimed business's
 * claim_source (migration 000041, written once at the G1
 * claim checkpoint) is the authoritative channel. The
 * self-reported signup_source is captured only when no claim
 * exists, is written exactly once, and effectiveChannel()
 * prefers claim_source whenever it is set, so the field can
 * never overwrite or contradict the claim record. The acq_
 * UTM columns are a separate machine attribution and are
 * never merged into this channel.
 */
class SignupSource
{
    /** The stored value of businesses.claim_source for a G1 claim. */
    public const CLAIM_CHANNEL = 'claimable_page';

    /**
     * The fixed option set: stored key => the owner's own
     * words on the capture panel [SRC-01]. Order is the
     * panel's order.
     *
     * @var array<string, string>
     */
    public const OPTIONS = [
        'walk_in' => 'Someone from Easy Refer came into my shop',
        'decal_poster' => 'I saw a sign or poster in another shop',
        'owner_referral' => 'Another shop owner told me',
        'claim_card' => 'I got a card or link made for my shop',
        'demo' => 'I tried the demo',
        'online' => 'I found it online',
        'other' => 'Something else',
    ];

    /**
     * Readout labels (admin console): the same channels in the
     * platform's words, plus the claim channel and the
     * unrecorded state.
     *
     * @var array<string, string>
     */
    public const READOUT_LABELS = [
        'walk_in' => 'Walk-in visit',
        'decal_poster' => 'Saw a decal or poster',
        'owner_referral' => 'Another owner told them',
        'claim_card' => 'Claim card or link for their shop',
        'demo' => 'Tried the demo',
        'online' => 'Found it online',
        'other' => 'Other',
        self::CLAIM_CHANNEL => 'Claimed their ready-made shop page',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::OPTIONS);
    }

    /**
     * Whether the capture panel is offered to this business:
     * only while nothing is recorded, and never to a claimed
     * business (its channel is already authoritative).
     */
    public static function captureOffered(Business $business): bool
    {
        return $business->claim_source === null && $business->signup_source === null;
    }

    /**
     * The business's signup channel for the Friday readout:
     * the claim channel when the business claimed its page,
     * otherwise its self-reported source, otherwise null.
     */
    public static function effectiveChannel(Business $business): ?string
    {
        if ($business->claim_source !== null) {
            return $business->claim_source;
        }

        return $business->signup_source;
    }

    /** The readout label for the effective channel. */
    public static function effectiveLabel(Business $business): string
    {
        $channel = self::effectiveChannel($business);

        if ($channel === null) {
            return 'Not recorded';
        }

        return self::READOUT_LABELS[$channel] ?? $channel;
    }
}
