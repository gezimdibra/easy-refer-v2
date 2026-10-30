<?php

namespace App\Support;

/**
 * G1 generation configuration (design pass §1): the Places
 * primary-type -> product business-type mapping, and the ONE
 * starter reward the demo wears per trade, taken from the shipped
 * C1 packs (starter-packs.md). Pizza has no shipped pack, so it
 * falls to the generic 20% starter (PACK-G1 form) exactly as §1
 * rules; an unshipped pack may never present as product.
 */
final class ClaimStarters
{
    /** Places primary type => [business_type, trade label]. */
    public const TYPE_MAP = [
        'barber_shop' => ['barber', 'barber shop'],
        'hair_salon' => ['salon', 'hair salon'],
        'beauty_salon' => ['salon', 'salon'],
        'cafe' => ['cafe', 'cafe'],
        'coffee_shop' => ['cafe', 'cafe'],
        'pizza_restaurant' => ['restaurant', 'pizza shop'],
        'restaurant' => ['restaurant', 'restaurant'],
    ];

    public const GENERIC_REWARD = '20% off a first visit';

    /** business_type => the shipped pack's friend-reward starter (§1). */
    public const STARTER_BY_TYPE = [
        'barber' => '$10 off your first cut',
        'salon' => '$15 off a color service',
        'cafe' => 'A free regular coffee',
    ];

    /** business_type => the G1-C07 placement IDEAS line (C1 pack pattern). */
    public const PLACEMENT_BY_TYPE = [
        'barber' => 'Put the poster by the register, where people wait to pay. Ask a happy customer to send it to one friend before they leave.',
        'salon' => 'Put the poster by the register, where people wait to pay. Ask a happy customer to send it to one friend before they leave.',
        'cafe' => 'Put the poster by the register, where people wait to pay. Ask a happy customer to send it to one friend before they leave.',
        'restaurant' => 'Put the poster by the register, where people wait to pay. Ask a happy customer to send it to one friend before they leave.',
    ];

    /** @return array{0: string, 1: string}|null [business_type, trade label] */
    public static function mapPlacesType(?string $placesType): ?array
    {
        return $placesType !== null ? (self::TYPE_MAP[$placesType] ?? null) : null;
    }

    public static function starterFor(?string $businessType): string
    {
        return self::STARTER_BY_TYPE[$businessType] ?? self::GENERIC_REWARD;
    }

    public static function placementLine(?string $businessType): string
    {
        return self::PLACEMENT_BY_TYPE[$businessType]
            ?? 'Put the poster by the register, where people wait to pay. Ask a happy customer to send it to one friend before they leave.';
    }
}
