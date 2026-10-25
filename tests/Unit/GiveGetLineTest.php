<?php

namespace Tests\Unit;

use App\Models\Business;
use PHPUnit\Framework\TestCase;

/**
 * QRK-01: the give/get composer (qr-growth-kit-design.md
 * Section 2, copy inventory REF-POSTER-02a). One composer
 * renders the poster's pass-2 line from BOTH reward texts, so
 * the scanner sees their own payoff next to the friend's. The
 * four cases are byte-exact; the "Give" side derives from the
 * friend reward's leading figure (the poster's own extraction)
 * so it never prints a whole sentence, and a reward text
 * carrying "first" can never double the first-visit phrase
 * (the F-2 pin pattern, applied to the composer pair).
 */
class GiveGetLineTest extends TestCase
{
    private function business(?string $friend, ?string $referrer): Business
    {
        $business = new Business;
        $business->friend_reward_text = $friend;
        $business->referrer_reward_text = $referrer;

        return $business;
    }

    public function test_both_rewards_set_compose_the_dual_sided_line(): void
    {
        $this->assertSame(
            'Give $10 off. Get $10 off your next cut when your friend visits.',
            $this->business('$10 off your first cut', '$10 off your next cut')->giveGetLine()
        );
    }

    public function test_give_side_derives_percent_and_percent_word_figures(): void
    {
        $this->assertSame(
            'Give 20% off. Get a free coffee when your friend visits.',
            $this->business('20% off your first visit', 'a free coffee')->giveGetLine()
        );

        $this->assertSame(
            'Give 10 percent off. Get $5 off when your friend visits.',
            $this->business('10 percent off', '$5 off')->giveGetLine()
        );
    }

    public function test_give_side_keeps_a_figure_without_off_and_a_text_without_a_figure(): void
    {
        $this->assertSame(
            'Give $10. Get $10 gift card when your friend visits.',
            $this->business('$10 gift card', '$10 gift card')->giveGetLine()
        );

        $this->assertSame(
            'Give A free coffee. Get $10 off when your friend visits.',
            $this->business('A free coffee', '$10 off')->giveGetLine()
        );
    }

    public function test_trailing_periods_are_stripped_from_both_texts(): void
    {
        $this->assertSame(
            'Give $10 off. Get $10 off when your friend visits.',
            $this->business('$10 off.', '$10 off.')->giveGetLine()
        );
    }

    public function test_a_space_before_a_trailing_period_never_reaches_the_line(): void
    {
        // Train 1 fix round F-11: owners type rewards with a
        // space before the period ("A free coffee ."). The
        // composer strips the period; the space it leaves
        // behind must go too, or the line prints "Give A free
        // coffee . Get ..." with the space before the period,
        // and the referrer side doubles its space before
        // "when".
        $this->assertSame(
            'Give A free coffee. Get $10 off when your friend visits.',
            $this->business('A free coffee .', '$10 off')->giveGetLine()
        );

        $this->assertSame(
            'Give $10 off. Get a free pastry when your friend visits.',
            $this->business('$10 off', 'a free pastry .')->giveGetLine()
        );

        $this->assertSame(
            'Give $10 off. Get a free pastry when your friend visits.',
            $this->business('$10 off .', 'a free pastry')->giveGetLine()
        );

        $this->assertSame(
            'Your friend gets a reward on their first visit. You get a free pastry when they visit.',
            $this->business('', 'a free pastry .')->giveGetLine()
        );
    }

    public function test_friend_only_falls_back_to_the_share_line(): void
    {
        $this->assertSame(
            'Share it with a friend. You get a reward when they visit.',
            $this->business('$10 off', '')->giveGetLine()
        );

        $this->assertSame(
            'Share it with a friend. You get a reward when they visit.',
            $this->business('$10 off', null)->giveGetLine()
        );
    }

    public function test_referrer_only_names_the_friends_reward_without_a_text(): void
    {
        $this->assertSame(
            'Your friend gets a reward on their first visit. You get $10 off your next cut when they visit.',
            $this->business('', '$10 off your next cut')->giveGetLine()
        );

        $this->assertSame(
            'Your friend gets a reward on their first visit. You get $10 off your next cut when they visit.',
            $this->business('   ', '$10 off your next cut')->giveGetLine()
        );
    }

    public function test_neither_set_falls_back_to_the_plain_line(): void
    {
        $this->assertSame(
            'Scan, share, and you both get rewarded when your friend visits.',
            $this->business('', '')->giveGetLine()
        );

        $this->assertSame(
            'Scan, share, and you both get rewarded when your friend visits.',
            $this->business(null, null)->giveGetLine()
        );
    }

    public function test_a_reward_carrying_first_never_doubles_the_first_visit_phrase(): void
    {
        $business = $this->business('$10 off your first visit', '$10 off');

        // The headline composer (F-2) already stands alone when
        // the reward carries "first"; the give/get line must
        // not re-introduce the phrase on either side.
        $this->assertSame('Get $10 off your first visit', $business->friendRewardHeadline());
        $this->assertSame(
            'Give $10 off. Get $10 off when your friend visits.',
            $business->giveGetLine()
        );
        $this->assertSame(0, substr_count($business->giveGetLine(), 'first'));
    }
}
