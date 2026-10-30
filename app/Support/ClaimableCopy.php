<?php

namespace App\Support;

use App\Models\ClaimablePage;

/**
 * The G1 copy of record (g1-claimable-design-pass.md §10.2): every
 * string on the claim page, numbered G1-C01..G1-C41, rendered from
 * here and nowhere else, so the truth sweep and the build's pin
 * tests cite one source. §10.3's truth table is TRUTH_TABLE below:
 * the states in which each string may render. A string rendering
 * outside its valid states is a state-blind copy defect (SA-01
 * class). Slots ({Shop}, {trade}, {reward}, {date}, {last two},
 * {expiry date}) fill only from the page snapshot/register; a
 * string whose slot cannot fill truthfully does not render.
 */
final class ClaimableCopy
{
    /**
     * §10.3 truth table: string id => the page states it is true in.
     * States are the §5 P-states and §8 R-states, plus the two
     * hand-off surfaces (signup, onboarding) named in the table.
     *
     * @var array<string, list<string>>
     */
    public const TRUTH_TABLE = [
        'C01' => ['P1', 'P2', 'P3', 'P4'],
        'C02' => ['P2', 'P3'],
        'C03' => ['P1', 'P2', 'P3', 'P4'],
        'C04' => ['P2', 'P3'],
        'C05' => ['P2', 'P3'],
        'C06' => ['P2', 'P3'],
        'C07' => ['P2', 'P3'],
        'C08' => ['P2', 'P3'],
        'C09' => ['P2', 'P3'],
        'C10' => ['P2', 'P3'],
        'C11' => ['P2', 'P3'],
        'C12' => ['P2', 'P3'],
        'C13' => ['P2', 'P3'],
        'C14' => ['P2', 'P3'],
        'C15' => ['P2', 'P3', 'P4'],
        'C16' => ['P2', 'P3', 'P4'],
        'C17' => ['P2', 'P3', 'P4'],
        'C18' => ['P2', 'P3', 'P4'],
        'C19' => ['P2', 'P3', 'P4'],
        'C20' => ['P2'],
        'C21' => ['P3', 'signup-before-checkpoint'],
        'C22' => ['P3'],
        'C23' => ['P3'],
        'C24' => ['P3'],
        'C25' => ['P3'],
        'C25-lock-sends' => ['P3'],
        'C25-lock-wrong' => ['P3'],
        'C25-send-failed' => ['P3'],
        'C26' => ['signup-step-2'],
        'C27' => ['signup-step-1-claim-path'],
        'C28' => ['onboarding-location-claim-path'],
        'C29' => ['onboarding-rewards-claim-path'],
        'C30' => ['P3'],
        'C31' => ['P4'],
        'C32' => ['P4'],
        'C33' => ['P2', 'P3'],
        'C34' => ['P1', 'P2', 'P3', 'P4'],
        'C35' => ['P8', 'R0', 'R1', 'R2', 'R3', 'R4'],
        'C36' => ['R0'],
        'C37' => ['P3-killed-by-removal', 'P4-killed-by-removal'],
        'C38' => ['P5'],
        'C39' => ['P5'],
        'C40' => ['P7', 'P9'],
        'C41' => ['contested-intake'],
    ];

    /** §10.4 negative inventory: phrases that must never render. */
    public const BANNED = [
        'your program is ready', 'already set up', 'other shops near you',
        'someone else may claim', 'running in under a minute', 'live in a minute',
        'free program', 'verified visit', 'claim token', 'snapshot',
    ];

    private static function fill(string $template, ClaimablePage $page, array $extra = []): string
    {
        $map = array_merge([
            '{Shop}' => (string) $page->shop_name,
            '{trade}' => (string) $page->trade_label,
            '{reward}' => (string) $page->starter_reward_text,
            '{date}' => $page->snapshot_fetched_at?->format('F j, Y') ?? '',
            '{last two}' => $page->listedPhoneLastTwo() ?? '',
            '{expiry date}' => $page->expires_at?->format('F j, Y') ?? '',
        ], $extra);

        return strtr($template, $map);
    }

    public static function c01(ClaimablePage $p): string
    {
        return self::fill('Preview. Easy Refer made this page for {Shop}. {Shop} is not using Easy Refer yet, and nothing on this page is live.', $p);
    }

    public static function c02(ClaimablePage $p): string
    {
        return self::fill('Easy Refer made this page from {Shop}\'s public listing, so the owner can see their poster and try how it works. The shop did not ask for this page and is not connected with it. Details from your public listing, checked {date}.', $p);
    }

    public static function c03(ClaimablePage $p): string
    {
        return self::fill('Made by Easy Refer for {Shop}. This page is private: it is not listed anywhere public.', $p);
    }

    public static function c04(ClaimablePage $p): string
    {
        return self::fill('I bring you customers in. Your own customers send their friends to {Shop}. You pay only when a friend actually walks in.', $p);
    }

    public static function c05(): string
    {
        return 'How it works. 1. Claim this page. Your poster is already made. 2. Put the poster up. Customers scan it and send it to their friends.';
    }

    public static function c06(ClaimablePage $p): string
    {
        return self::fill('Start with this reward: {reward}. It is our suggestion for a {trade}; you can change it in one tap when you claim, and any time after.', $p);
    }

    /** Per-trade placement IDEAS line; the pattern is the C1 pack's. */
    public static function c07(ClaimablePage $p): string
    {
        return ClaimStarters::placementLine($p->business_type);
    }

    public static function c08(): string
    {
        return 'Your counter poster, already made with your name on it. This print is a preview. The code on it is a preview code: it opens this page only, and it cannot sign anyone up.';
    }

    public static function c09(): string
    {
        return 'Preview, not live yet.';
    }

    public static function c10(): string
    {
        return 'Try it as a customer. This is a demo of what your customers would do. Nothing here is real, and nothing you tap sends anything to anyone.';
    }

    public static function c11(): string
    {
        return 'Demo';
    }

    public static function c12(): string
    {
        return 'Example customer';
    }

    public static function c13(): string
    {
        return 'In your real program, a customer confirms their own phone number here. In this demo the number is made up, and no text is sent.';
    }

    public static function c14(): string
    {
        return 'In your real program this button sends the customer\'s own link to a friend. In this demo it sends nothing.';
    }

    /** @return list<string> The cost block, one promises-register sentence per line, in page order. */
    public static function costBlock(): array
    {
        return [
            'C15' => '$0 a month. No setup fee.',
            'C16' => 'Your first 5 visits are free.',
            'C17' => 'After that, $2 for each visit that counts: a friend who walks in and uses their reward.',
            'C18' => 'Never more than $99 a month in visit charges.',
            'C19' => 'If you ever send bulk texts to your customers, they cost 2 cents a segment for each person. You always see the cost before you send.',
        ];
    }

    public static function c20(ClaimablePage $p): string
    {
        return self::fill('Claim this page. Claiming creates your account with {Shop}\'s details already filled in. It takes about a minute or two, and nothing is charged for claiming.', $p);
    }

    public static function c21(): string
    {
        return 'Leaving is fine. Nothing is made, and nothing is charged, until you create your account.';
    }

    public static function c22(ClaimablePage $p): string
    {
        return self::fill('We will send a 6-digit code to the phone number on {Shop}\'s public listing, ending in {last two}. Pick how it should arrive:', $p);
    }

    /** @return array{sms: string, call: string} */
    public static function c23(): array
    {
        return ['sms' => 'Text me the code', 'call' => 'Call me with the code'];
    }

    public static function c24(): string
    {
        return 'Answering that phone shows us the claim is coming from the shop. It is the same check listing sites use when an owner claims a listing.';
    }

    public static function c25(): string
    {
        return 'That code did not match. Codes expire after 10 minutes, so you can send a new one.';
    }

    public static function c25Expired(): string
    {
        return 'That code has expired. Codes expire after 10 minutes, so you can send a new one.';
    }

    /**
     * The gate send's provider-error line (C25 family, P3): the
     * provider refused or failed the send itself, so no code is
     * on its way. It asserts no falsifiable state — the other
     * channel and the §4 fallback are both still open — and it
     * lives here with the rest of the gate's error copy, not
     * hard-coded in the controller (copy-train fix: the send
     * path was the one gate string outside the inventory).
     */
    public static function c25SendFailed(): string
    {
        return 'That code could not be sent. You can try the other way, or leave your name below and we will check the claim with the shop.';
    }

    /**
     * The gate-lock errors (C25 family, P3): the claim gate's lock
     * is a ROLLING 24h from the lock hit, so no fixed phrase like
     * "tomorrow" can be true in every state (state-truth rule).
     * The sentence therefore names the concrete reopen moment the
     * lock state supports — ClaimGateService::lockedUntil() — with
     * its timezone; when no lock moment is readable it falls back
     * to the neutral clause, which asserts no time at all.
     */
    public static function c25LockSends(?\DateTimeInterface $reopensAt = null): string
    {
        return 'Too many codes were sent for this page.'.self::reopenClause($reopensAt)
            .' Or leave your name below and we will check the claim with the shop.';
    }

    public static function c25LockWrongCodes(?\DateTimeInterface $reopensAt = null): string
    {
        return 'Too many wrong codes were entered.'.self::reopenClause($reopensAt)
            .' Or leave your name below and we will check the claim with the shop.';
    }

    private static function reopenClause(?\DateTimeInterface $reopensAt): string
    {
        if ($reopensAt === null) {
            return ' Claiming is paused for now.';
        }

        $at = \Carbon\Carbon::instance(
            $reopensAt instanceof \DateTimeImmutable ? \DateTime::createFromImmutable($reopensAt) : $reopensAt,
        );

        return ' Claiming opens again on '.$at->format('F j, Y').' at '.$at->format('g:i A T').'.';
    }

    public static function c26(ClaimablePage $p): string
    {
        return self::fill('From {Shop}\'s public listing, checked {date}. Keep it if it is the number you want on your account, or change it.', $p);
    }

    public static function c27(ClaimablePage $p): string
    {
        return self::fill('This is what we have for {Shop}. Fix anything that is wrong.', $p);
    }

    public static function c28(): string
    {
        return 'This is the address from your listing. It is the address your poster and your program use, so fix it if it is wrong.';
    }

    public static function c29(ClaimablePage $p): string
    {
        return self::c29For((string) $p->starter_reward_text);
    }

    /** C29 from the claimed business's own row (the onboarding twin). */
    public static function c29For(string $reward): string
    {
        return strtr('Your starting reward: {reward}. A friend gets it when they visit, and the customer who sent them gets it too. Keep it, or change it. You can change it any time in Settings.', ['{reward}' => $reward]);
    }

    public static function c30(): string
    {
        return 'If that phone cannot take a code, leave your name and a number where we can reach you. We will check this claim with the shop before anything is made.';
    }

    public static function c31(): string
    {
        return 'We are checking this claim with the shop. Nothing is live and no account has been made.';
    }

    public static function c32(): string
    {
        return 'A claim for this shop is being checked. The page cannot be claimed again until the check ends.';
    }

    public static function c33(ClaimablePage $p): string
    {
        return self::fill('We take previews down after {expiry date}, because the details go stale. Starting later is always fine, and starting the ordinary way costs nothing to begin.', $p);
    }

    public static function c34(): string
    {
        return 'Not your shop, or do not want this page? Take this page down.';
    }

    public static function c35(): string
    {
        return 'This preview has been taken down at the shop\'s request.';
    }

    public static function c36(ClaimablePage $p): string
    {
        return self::fill('Done. This page is down, and we will not make another page for {Shop} for at least 12 months. If you change your mind sooner, you can start the ordinary way at any time.', $p);
    }

    public static function c37(): string
    {
        return 'This page was taken down while you were using it, so claiming has stopped. Nothing was made and nothing was charged.';
    }

    public static function c38(ClaimablePage $p): string
    {
        return self::fill('{Shop} has claimed this page.', $p);
    }

    public static function c39(ClaimablePage $p): string
    {
        return self::fill('If you run {Shop} and you did not claim this page, tell us here. We will check with the shop, and if the claim was not yours, the shop and anything real in it move to you and the other account\'s access ends.', $p);
    }

    public static function c40(ClaimablePage $p): string
    {
        return self::fill('This preview has ended. {Shop} can still start free any time. Nothing about the shop was changed.', $p);
    }

    public static function c41(): string
    {
        return 'Tell us your name and how to reach you. We check every contested claim with the shop at its listed phone or address before anything changes.';
    }
}
