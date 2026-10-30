<?php

namespace Tests\Unit;

use App\Models\Business;
use PHPUnit\Framework\TestCase;

/**
 * Amendment 58 [REF-POSTER-04]: the poster headline composer
 * (owner ruling 2026-10-29, Finding 4). The headline leads
 * with the reward and the easy action in the give/get
 * pattern; "join" and "program" never appear; both sides'
 * rewards are anchored to the friend's visit ("when they
 * visit"), which is the timing truth in every state — the
 * friend's reward is given at the visit, the referrer's is
 * earned at the visit (availability vests per OD-3 and is
 * stated by the flow's reward pages, never promised
 * instant here). Configuration branches on the OR-1 flag
 * alone: a split-equal row composes the split form.
 */
class PosterHeadlineTest extends TestCase
{
    private function business(?string $friend, ?string $referrer, bool $split): Business
    {
        $business = new Business;
        $business->friend_reward_text = $friend;
        $business->referrer_reward_text = $referrer;
        $business->reward_split = $split;

        return $business;
    }

    public function test_unified_headline_shares_and_names_the_one_reward(): void
    {
        $this->assertSame(
            'Share with a friend, you both get $10 off when they visit.',
            $this->business('$10 off', null, false)->posterHeadline()
        );

        $this->assertSame(
            'Share with a friend, you both get A free coffee when they visit.',
            $this->business('A free coffee', null, false)->posterHeadline()
        );

        $this->assertSame(
            'Share with a friend, you both get 20% off your first visit when they visit.',
            $this->business('20% off your first visit', null, false)->posterHeadline()
        );
    }

    public function test_split_headline_gives_the_friend_reward_and_gets_the_referrer_reward(): void
    {
        $this->assertSame(
            'Give a friend $10 off your first cut. Get $10 off your next cut when they visit.',
            $this->business('$10 off your first cut', '$10 off your next cut', true)->posterHeadline()
        );

        // SPLIT-EQUAL is a real state (OR-1): flag on, texts
        // equal — it composes the split form, not unified.
        $this->assertSame(
            'Give a friend $10 off. Get $10 off when they visit.',
            $this->business('$10 off', '$10 off', true)->posterHeadline()
        );
    }

    public function test_unset_headline_names_no_amount_and_no_banned_words(): void
    {
        foreach ([null, '', '   '] as $friend) {
            $headline = $this->business($friend, null, false)->posterHeadline();
            $this->assertSame('Share with a friend, you both get rewarded when they visit.', $headline);
        }
    }

    public function test_trailing_periods_never_double_the_headline_punctuation(): void
    {
        $this->assertSame(
            'Give a friend $10 off. Get $10 off when they visit.',
            $this->business('$10 off.', '$10 off.', true)->posterHeadline()
        );

        $this->assertSame(
            'Share with a friend, you both get $10 off when they visit.',
            $this->business('$10 off .', null, false)->posterHeadline()
        );
    }

    public function test_no_configuration_ever_prints_a_banned_word_or_an_em_dash(): void
    {
        $cases = [
            $this->business('$10 off', null, false),
            $this->business('$10 off your first cut', '$10 off your next cut', true),
            $this->business(null, null, false),
        ];

        foreach ($cases as $business) {
            $headline = $business->posterHeadline();
            $this->assertSame(0, preg_match('/\bjoin\b/i', $headline), $headline);
            $this->assertSame(0, preg_match('/\bprogram\b/i', $headline), $headline);
            $this->assertStringNotContainsString("\u{2014}", $headline, $headline);
        }
    }
}
