<?php

namespace Tests\Unit;

use App\Services\Referral\MessageCopy;
use App\Models\Business;
use App\Support\SmsSegments;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Exhaustive pins for the SMS segment counter (spec Section 10.3,
 * Phase 4 coverage map row 8). SmsSegments is a pure function that
 * decides what a send costs (2 cents per segment per recipient),
 * so every boundary of the GSM-7 and UCS-2 packing rules is pinned
 * here at unit speed: 160/153 for GSM-7, 70/67 for UCS-2, extension
 * characters at double units, and the characters that silently
 * flip a body to UCS-2 (emoji, smart quotes, backtick, tab).
 */
class SmsSegmentsTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function gsmProvider(): array
    {
        return [
            'empty body is zero segments' => ['', 0],
            'single character' => ['a', 1],
            'exactly 160 GSM-7 chars is one segment' => [str_repeat('a', 160), 1],
            '161 GSM-7 chars needs two segments' => [str_repeat('a', 161), 2],
            '306 chars fills two concatenated segments exactly' => [str_repeat('a', 306), 2],
            '307 chars spills into a third segment' => [str_repeat('a', 307), 3],
            '459 chars fills three concatenated segments exactly' => [str_repeat('a', 459), 3],
            '460 chars spills into a fourth segment' => [str_repeat('a', 460), 4],
            'digits and punctuation are basic table' => ['Order 42: pay $5 (cash) & go!', 1],
            'the full extension set in one short body' => ['^{}\\[~]|€', 1],
            'euro sign costs two units: 158 + euro = 160 units, one segment' => [str_repeat('a', 158).'€', 1],
            'euro sign costs two units: 159 + euro = 161 units, two segments' => [str_repeat('a', 159).'€', 2],
            '80 euros are exactly 160 units, one segment' => [str_repeat('€', 80), 1],
            '81 euros are 162 units, two segments' => [str_repeat('€', 81), 2],
            'extension braces inside the limit: 152 + 4 units of braces = 156' => [str_repeat('a', 152).'{}', 1],
            'extension braces past the limit: 157 + 4 units of braces = 161' => [str_repeat('a', 157).'{}', 2],
            'greek capitals from the basic table stay GSM-7' => ['ΔΦΓΛΩΠΨΣΘΞ', 1],
            'latin-1 letters from the basic table stay GSM-7' => ['Café ñandú Über señor', 1],
            'newline and carriage return are basic table' => ["line one\nline two\r\n", 1],
            'underscore and at-sign are basic table' => ['follow @easy_refer_now', 1],
        ];
    }

    #[DataProvider('gsmProvider')]
    public function test_gsm7_bodies(string $body, int $expected): void
    {
        $this->assertSame($expected, SmsSegments::count($body));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function ucs2Provider(): array
    {
        return [
            'one CJK character forces UCS-2, one segment' => ['漢', 1],
            'exactly 70 UCS-2 units is one segment' => [str_repeat('漢', 70), 1],
            '71 UCS-2 units needs two segments' => [str_repeat('漢', 71), 2],
            '134 units fills two concatenated segments exactly' => [str_repeat('漢', 134), 2],
            '135 units spills into a third segment' => [str_repeat('漢', 135), 3],
            'one emoji is a surrogate pair: two UTF-16 units' => ['😀', 1],
            '35 emoji are exactly 70 units, one segment' => [str_repeat('😀', 35), 1],
            '36 emoji are 72 units, two segments' => [str_repeat('😀', 36), 2],
            '68 letters plus an emoji is exactly 70 units' => [str_repeat('a', 68).'😀', 1],
            '69 letters plus an emoji is 71 units, two segments' => [str_repeat('a', 69).'😀', 2],
            'a curly apostrophe flips the whole body to UCS-2' => [str_repeat('a', 70).'’', 2],
            '69 letters, a curly apostrophe, and a letter make 71 UCS-2 units' => [str_repeat('a', 69).'’a', 2],
            'a backtick is not in the GSM-7 table and forces UCS-2' => [str_repeat('a', 99).'`', 2],
            'a tab is not a GSM-7 character and forces UCS-2' => [str_repeat('a', 99)."\t", 2],
            'an em dash forces UCS-2' => ['Reward — ready', 1],
            'mixed GSM text with one CJK char bills as UCS-2 throughout' => [str_repeat('a', 100).'漢', 2],
        ];
    }

    #[DataProvider('ucs2Provider')]
    public function test_ucs2_bodies(string $body, int $expected): void
    {
        $this->assertSame($expected, SmsSegments::count($body));
    }

    /**
     * The strings that actually get billed: every MessageCopy
     * template rendered with representative values, counted by
     * hand from the GSM-7 rules (all-basic bodies: segments are
     * 1 up to 160 chars, then ceil(chars / 153)).
     */
    public function test_message_copy_templates_render_and_count(): void
    {
        $business = new Business(['name' => "Luigi's Pizza"]);
        $claimUrl = 'https://easy-refer.com/new/refer/c/AbCdEfGhIjKlMnOpQrStUv';
        $shareUrl = 'https://easy-refer.com/new/refer/s/XyZ123';

        // 163 basic chars: over the 160 single-segment limit, so
        // the claim link makes the reward-ready text a 2-segment
        // send (the blueprint's known cost shape for SMS-02).
        $rewardReady = MessageCopy::rewardReady($business, '$10 off', $claimUrl);
        $this->assertSame(163, mb_strlen($rewardReady));
        $this->assertSame(2, SmsSegments::count($rewardReady));

        // 118 basic chars: one segment.
        $friendLink = MessageCopy::friendLink($business, 'Marco', $shareUrl);
        $this->assertSame(118, mb_strlen($friendLink));
        $this->assertSame(1, SmsSegments::count($friendLink));

        // 120 basic chars: one segment.
        $ownerAdd = MessageCopy::ownerAddConfirmation($business);
        $this->assertSame(120, mb_strlen($ownerAdd));
        $this->assertSame(1, SmsSegments::count($ownerAdd));

        // 170 basic chars: two segments.
        $shareLink = MessageCopy::shareLink($business, $shareUrl);
        $this->assertSame(170, mb_strlen($shareLink));
        $this->assertSame(2, SmsSegments::count($shareLink));

        // 95 basic chars with a one-letter business name: one.
        $alert = MessageCopy::firstVisitAlert(new Business(['name' => 'A']), 'https://easy-refer.com/new/auth');
        $this->assertSame(95, mb_strlen($alert));
        $this->assertSame(1, SmsSegments::count($alert));
    }
}
