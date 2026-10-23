<?php

namespace App\Support;

/**
 * The reward starter packs (spec Section 5.5, copy inventory
 * PACK-00 and PACK-B/S/C/T/G rows): three prewritten friend and
 * referrer reward pairs per business type, each with a one-line
 * typical-cost note labeled as guidance. The strings live here,
 * transcribed from the inventory, so the Overview card and any
 * future picker render the same packs; choosing a pack only
 * prefills the OD-2 reward fields through the one write path
 * (RewardTextController) and changes nothing about the model.
 */
final class StarterPacks
{
    /** @var array<string, list<array{friend: string, referrer: string, cost: string}>> */
    private const PACKS = [
        'barber' => [
            ['friend' => 'A free beard trim with your haircut', 'referrer' => 'A free beard trim', 'cost' => 'A few minutes of chair time. Feels like a full upgrade to the client.'],
            ['friend' => '$10 off your first cut', 'referrer' => '$10 off your next cut', 'cost' => 'Simple and even. Most barbers start here.'],
            ['friend' => 'A free hot towel upgrade with your cut', 'referrer' => 'A free beard trim', 'cost' => 'Low product cost, high perceived value.'],
        ],
        'salon' => [
            ['friend' => '$15 off a color service', 'referrer' => '$15 off your next service', 'cost' => 'A small slice of a color ticket that brings a new client in.'],
            ['friend' => 'A free deep conditioning treatment with any service', 'referrer' => 'A free deep conditioning treatment', 'cost' => 'Costs product and minutes, reads as luxury.'],
            ['friend' => 'A free blowout with your first cut', 'referrer' => 'A free blowout', 'cost' => 'Shows off your best work to a brand-new client.'],
        ],
        'cafe' => [
            ['friend' => 'A free regular coffee', 'referrer' => 'A free pastry', 'cost' => 'Costs you about $1.10. Feels like $4.50 to the customer.'],
            ['friend' => 'A free pastry with any drink', 'referrer' => 'A free regular coffee', 'cost' => 'Uses items already in the case.'],
            ['friend' => 'Buy one drink, get one free', 'referrer' => 'A free drink on your next visit', 'cost' => 'The friend reward brings two people through the door.'],
        ],
        'boutique' => [
            ['friend' => '10 percent off your first purchase', 'referrer' => '10 percent off your next purchase', 'cost' => 'A small margin share for a new regular.'],
            ['friend' => 'Early access to the new drop', 'referrer' => 'Early access, plus a free gift with purchase', 'cost' => 'No discount at all. Access is the reward.'],
            ['friend' => 'A free gift with your first purchase', 'referrer' => 'A free gift with your next purchase', 'cost' => 'Pick a gift that costs you little and displays well.'],
        ],
        'generic' => [
            ['friend' => '10 percent off your first visit', 'referrer' => '10 percent off your next visit', 'cost' => 'The safe default pair.'],
            ['friend' => 'A free upgrade on your first visit', 'referrer' => 'A free upgrade on your next visit', 'cost' => 'Costs little when the upgrade is your time.'],
            ['friend' => 'A free add-on with your first service', 'referrer' => 'A free add-on with your next service', 'cost' => 'Choose an add-on with a low real cost.'],
        ],
    ];

    /**
     * The three packs for a business type; any type without its
     * own set (including "other" and an unset type) gets the
     * generic pack.
     *
     * @return list<array{friend: string, referrer: string, cost: string}>
     */
    public static function forType(?string $businessType): array
    {
        return self::PACKS[$businessType] ?? self::PACKS['generic'];
    }
}
