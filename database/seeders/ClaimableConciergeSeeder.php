<?php

namespace Database\Seeders;

use App\Services\Claimable\ClaimableService;
use Illuminate\Database\Seeder;

/**
 * G1 §11 CONCIERGE TEST — the THREE hand-made pages, built by hand
 * through the machinery before any generator exists (the generator
 * is deferred by the pass's own gate; see the build report).
 *
 * FIXTURE WARNING: the shop facts below are build-verification
 * fixtures in the §11 shape (one street, the three shipped-pack
 * trades: barber, salon, cafe). The listed phones are fictional
 * 555-range numbers so a fixture page can never send a gate code
 * to a real stranger. Before ANY §11 walk or distribution, the
 * operator replaces every field with the shop's verified public
 * listing facts and runs the §11.1 pre-flight (inert code scan,
 * listed-phone match, H9 dedupe, negative-inventory grep, cold
 * phone open). Distribution is owner-gated; this seeder sends
 * nothing and marks nothing sent.
 */
class ClaimableConciergeSeeder extends Seeder
{
    public function run(ClaimableService $service): void
    {
        $shops = [
            [
                'placeId' => 'concierge-fixture-bay-st-barber',
                'snapshot' => [
                    'displayName' => 'Bay Street Barber Co.',
                    'placesType' => 'barber_shop',
                    'street' => '412 Bay Street',
                    'city' => 'Staten Island',
                    'state' => 'NY',
                    'postalCode' => '10304',
                    'nationalPhoneNumber' => '+17185550101',
                    'businessStatus' => 'OPERATIONAL',
                ],
            ],
            [
                'placeId' => 'concierge-fixture-bay-st-salon',
                'snapshot' => [
                    'displayName' => 'Bay Street Salon',
                    'placesType' => 'hair_salon',
                    'street' => '438 Bay Street',
                    'city' => 'Staten Island',
                    'state' => 'NY',
                    'postalCode' => '10304',
                    'nationalPhoneNumber' => '+17185550102',
                    'businessStatus' => 'OPERATIONAL',
                ],
            ],
            [
                'placeId' => 'concierge-fixture-bay-st-cafe',
                'snapshot' => [
                    'displayName' => 'Corner Cup Cafe',
                    'placesType' => 'cafe',
                    'street' => '455 Bay Street',
                    'city' => 'Staten Island',
                    'state' => 'NY',
                    'postalCode' => '10304',
                    'nationalPhoneNumber' => '+17185550103',
                    'businessStatus' => 'OPERATIONAL',
                ],
            ],
        ];

        foreach ($shops as $shop) {
            [$page, $plainToken] = $service->generate(
                $shop['placeId'],
                $shop['snapshot'],
                'concierge',
                ClaimableService::CONCIERGE_SOURCE,
            );

            // The plain token is printed for the operator exactly
            // once, here: it is the claim card's link. Only its
            // hash is stored.
            $this->command?->info("Concierge page #{$page->id} ({$page->shop_name}): /claim/{$plainToken}");
        }
    }
}
