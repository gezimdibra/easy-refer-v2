<?php

namespace App\Support;

/**
 * The ready-made campaign messages (LV-01, Train 2; copy
 * Amendment 22 CAMP-16..19). Choosing a preset creates a draft
 * with the preset body and lands on the confirm screen. The
 * bodies are owner-facing message text only: the locked
 * "{BusinessName}: " prefix and the STOP suffix are system
 * parts (MessageCopy applies them at render and send), so no
 * preset body ever contains them.
 */
final class CampaignPresets
{
    /**
     * @return array<string, array{name: string, body: string}>
     */
    public static function all(): array
    {
        return [
            'thank_and_refer' => [
                'name' => 'Thank your customers and ask for one referral',
                'body' => 'Thank you for being a customer. If someone you know would love it here, share your referral link with them: when they visit, you both get a reward.',
            ],
            'slow_day' => [
                'name' => 'Fill a slow day',
                'body' => 'We have room today and would love to see you. Stop by, and bring a friend along: when they visit, you both get a reward.',
            ],
            'we_miss_you' => [
                'name' => 'We miss you',
                'body' => 'We miss you! It has been a while since your last visit. Come back and see us, and bring a friend: when they visit, you both get a reward.',
            ],
        ];
    }

    /**
     * @return array{name: string, body: string}|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
