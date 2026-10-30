<?php

namespace App\Support;

/**
 * The reward starter packs (spec Section 5.5, copy inventory
 * PACK-00 and PACK rows, as amended by Amendment 49 / OR-1):
 * three prewritten SINGLE rewards per business type, each with
 * a one-line typical-cost note labeled as guidance. ONE-REWARD
 * (owner ruling 2026-10-28): a pack is one reward — the
 * side-neutral core the old friend/referrer pair shared, with
 * "your first X" / "your next X" position words stripped,
 * because the same string must read correctly for a first
 * visit and for a return visit (design pass §2.5 table).
 * Choosing a pack only prefills the one reward field through
 * the one write path (RewardTextController / Settings) and
 * changes nothing about the model.
 */
final class StarterPacks
{
    /** @var array<string, list<array{reward: string, cost: string}>> */
    private const PACKS = [
        'barber' => [
            ['reward' => 'A free beard trim with your haircut', 'cost' => 'A few minutes of chair time. Feels like a full upgrade to the client.'],
            ['reward' => '$10 off', 'cost' => 'Simple and even.'],
            ['reward' => 'A free hot towel upgrade', 'cost' => 'Low product cost, high perceived value.'],
        ],
        'salon' => [
            ['reward' => '$15 off a color service', 'cost' => 'A small slice of a color ticket that brings a new client in.'],
            ['reward' => 'A free deep conditioning treatment', 'cost' => 'Costs product and minutes, reads as luxury.'],
            ['reward' => 'A free blowout', 'cost' => 'Shows off your best work to a brand-new client.'],
        ],
        'cafe' => [
            ['reward' => 'A free regular coffee', 'cost' => 'Example: about $1.10 of cost for a regular coffee, which customers value at more.'],
            ['reward' => 'A free pastry with any drink', 'cost' => 'Uses items already in the case.'],
            ['reward' => 'A free drink', 'cost' => 'A drink you already pour, given to a customer who brings you a new one.'],
        ],
        'restaurant' => [
            ['reward' => 'A free appetizer', 'cost' => 'A small plate from a kitchen that is already running.'],
            ['reward' => '$10 off', 'cost' => 'Simple and even.'],
            ['reward' => 'A free dessert', 'cost' => 'The last bite is the one they describe to friends.'],
        ],
        'boutique' => [
            ['reward' => '20% off', 'cost' => 'A small margin share for a new regular.'],
            ['reward' => 'Early access to the new drop', 'cost' => 'No discount at all. Access is the reward.'],
            ['reward' => 'A free gift with a purchase', 'cost' => 'Pick a gift that costs you little and displays well.'],
        ],
        'generic' => [
            ['reward' => '20% off', 'cost' => 'The safe default.'],
            ['reward' => 'A free upgrade', 'cost' => 'Costs little when the upgrade is your time.'],
            ['reward' => 'A free add-on with a service', 'cost' => 'Choose an add-on with a low real cost.'],
        ],
    ];

    /**
     * The three packs for a business type; any type without its
     * own set (including "other" and an unset type) gets the
     * generic pack.
     *
     * @return list<array{reward: string, cost: string}>
     */
    public static function forType(?string $businessType): array
    {
        return self::PACKS[$businessType] ?? self::PACKS['generic'];
    }

    /**
     * The prefilled starter answer for the signup reward step
     * (§3.2 B): the FIRST pack's reward for the business's
     * registered type; unknown/unset type gets the generic
     * pack's first reward, "20% off".
     *
     * @return array{reward: string, cost: string}
     */
    public static function starterFor(?string $businessType): array
    {
        return self::forType($businessType)[0];
    }
}
