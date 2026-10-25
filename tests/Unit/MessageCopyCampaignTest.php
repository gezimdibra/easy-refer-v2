<?php

namespace Tests\Unit;

use App\Models\Business;
use App\Services\Billing\SmsMetering;
use App\Services\Referral\MessageCopy;
use App\Support\SmsSegments;
use Tests\TestCase;

/**
 * The campaign body rendering (spec Section 10.3, inventory
 * SMS-04): the system prefix and the STOP suffix are render-time,
 * in exactly one place, and the segment math runs on the final
 * body, never on the owner's text alone.
 */
class MessageCopyCampaignTest extends TestCase
{
    private function business(string $name): Business
    {
        return (new Business)->forceFill(['name' => $name]);
    }

    public function test_suffix_boundary_matrix(): void
    {
        // SMS-04: no trailing period appends ". Reply STOP to opt
        // out."; a trailing period appends " Reply STOP to opt
        // out." so the rendered text never doubles the period.
        $this->assertSame('. Reply STOP to opt out.', MessageCopy::campaignSuffix('Spring sale'));
        $this->assertSame(' Reply STOP to opt out.', MessageCopy::campaignSuffix('Spring sale.'));
        $this->assertSame('. Reply STOP to opt out.', MessageCopy::campaignSuffix('Spring sale!'));
        $this->assertSame('. Reply STOP to opt out.', MessageCopy::campaignSuffix('Spring sale?'));
        $this->assertSame('. Reply STOP to opt out.', MessageCopy::campaignSuffix(''));
    }

    public function test_campaign_renders_prefix_body_and_suffix(): void
    {
        $business = $this->business('Harbor Street Barbers');

        $this->assertSame(
            'Harbor Street Barbers: Spring sale is on. Reply STOP to opt out.',
            MessageCopy::campaign($business, 'Spring sale is on')
        );
        $this->assertSame(
            'Harbor Street Barbers: Spring sale is on. Reply STOP to opt out.',
            MessageCopy::campaign($business, 'Spring sale is on.')
        );
    }

    public function test_encoding_flips_to_unicode_on_emoji_and_stays_gsm_on_extension_chars(): void
    {
        $this->assertSame('GSM-7', SmsSegments::encoding('Spring sale, 20% off everything'));
        $this->assertSame('GSM-7', SmsSegments::encoding('Price is €5 today'));
        $this->assertSame('Unicode', SmsSegments::encoding('Spring sale 🎉'));

        $gsm = MessageCopy::campaign($this->business('Biz'), 'Spring sale is on');
        $unicode = MessageCopy::campaign($this->business('Biz'), 'Spring sale is on 🎉');

        $this->assertSame('GSM-7', SmsSegments::encoding($gsm));
        $this->assertSame('Unicode', SmsSegments::encoding($unicode));
        // The emoji body is short enough to stay one segment either
        // way; the flip is in the encoding the estimate reports,
        // which is what triples bills at length.
        $this->assertSame(1, SmsSegments::count($unicode));
    }

    public function test_prefix_and_suffix_count_toward_segments(): void
    {
        $business = $this->business('Biz');

        // 131 chars of body + 24 of suffix = 155 GSM units: one
        // segment without the prefix; the 5-unit "Biz: " prefix
        // takes the final body to 160, still one segment.
        $oneSegment = MessageCopy::campaign($business, str_repeat('a', 131));
        $this->assertSame(160, strlen($oneSegment));
        $this->assertSame(1, SmsMetering::segmentsFor($oneSegment));

        // One more body char crosses the boundary: 161 units is
        // two segments, priced at two segments per recipient.
        $twoSegments = MessageCopy::campaign($business, str_repeat('a', 132));
        $this->assertSame(2, SmsMetering::segmentsFor($twoSegments));
        $this->assertSame(4, SmsMetering::priceForSegments(2));
    }
}
