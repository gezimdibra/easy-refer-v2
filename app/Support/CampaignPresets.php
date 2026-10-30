<?php

namespace App\Support;

use App\Models\SmsCampaign;

/**
 * The ready-made campaign messages (LV-01, Train 2; copy
 * Amendment 22 CAMP-16..19). Choosing a preset creates a draft
 * with the preset body and lands on the confirm screen. The
 * bodies are owner-facing message text only: the locked
 * "{BusinessName}: " prefix and the STOP suffix are system
 * parts (MessageCopy applies them at render and send), so no
 * preset body ever contains them.
 *
 * Idea Train 1 (Amendment 46) generalizes the preset record to
 * the ONE template system both new features use: key ->
 * {name, body, audience_segment?, origin}. ID-01's slow_day
 * stays a body-only preset (segment null, CAMP-18 verbatim);
 * ID-03's win_back_referrers is the first segment-carrying
 * preset (WB-01/WB-02): its body carries NO link and NO count,
 * and every sentence in it is true for every member of the
 * segment by the segment's construction.
 */
final class CampaignPresets
{
    /**
     * @return array<string, array{name: string, body: string, audience_segment: ?string, origin: string}>
     */
    public static function all(): array
    {
        return [
            'thank_and_refer' => [
                'name' => 'Thank your customers and ask for one referral',
                'body' => 'Thank you for being a customer. If someone you know would love it here, share your referral link with them: when they visit, you both get a reward.',
                'audience_segment' => null,
                'origin' => 'preset',
            ],
            'slow_day' => [
                'name' => 'Fill a slow day',
                'body' => 'We have room today and would love to see you. Stop by, and bring a friend along: when they visit, you both get a reward.',
                'audience_segment' => null,
                'origin' => 'slow_day_preset',
            ],
            'we_miss_you' => [
                'name' => 'We miss you',
                'body' => 'We miss you! It has been a while since your last visit. Come back and see us, and bring a friend: when they visit, you both get a reward.',
                'audience_segment' => null,
                'origin' => 'preset',
            ],
            'win_back_referrers' => [
                'name' => 'Win back past referrers',
                'body' => 'It has been a while since you sent a friend our way. Your referral link still works. Share it with a friend: when they visit, you both get a reward.',
                'audience_segment' => SmsCampaign::SEGMENT_LAPSED_REFERRERS,
                'origin' => 'winback_preset',
            ],
        ];
    }

    /**
     * @return array{name: string, body: string, audience_segment: ?string, origin: string}|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
