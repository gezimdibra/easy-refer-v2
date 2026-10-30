<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Referral;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * R4-U4 (Surface 3 pick A, "Flood+"; copy Amendment 29,
 * [R4U4-1]): the shared success flood keeps its medallion,
 * reward headline, and scale-in exactly, and gains the receipt
 * facts: the outcome word in success-strong under the headline
 * ("Reward redeemed" / "Reward claimed", never interchangeable),
 * the facts as labelled rows (Business / Confirmed) in the
 * well, and the timestamp in business-local time through
 * Business::localTime (Train 3.5): "Today, {time}" when the
 * confirmation falls on the business-local day itself,
 * otherwise the business-local date and time. The staff phone
 * and the customer phone share the partial, so both sides of
 * the counter read the same receipt.
 *
 * Time is frozen at 2026-10-26 17:00 UTC (1:00 PM in
 * America/New_York, the fixture business's zone) so the UTC
 * and business-local readings of every fixture instant differ
 * in hour, and one fixture differs in date as well.
 */
class ReceiptFloodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();

        $this->travelTo(Carbon::parse('2026-10-26 17:00:00', 'UTC'));
    }

    private function staffNonce(string $token): string
    {
        $response = $this->get(route('redeem.show', ['token' => $token]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'staff screen carries a nonce');

        return $m[1];
    }

    public function test_redeemed_receipt_shows_the_outcome_word_labelled_rows_and_today_in_business_local_time(): void
    {
        $business = World::business(['timezone' => 'America/New_York']);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        // 05:42 UTC is 1:42 AM the same day in New York: the
        // local reading is today's, at a different hour.
        [, $redeemToken] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => Carbon::parse('2026-10-26 05:42:00', 'UTC'),
        ]);

        $response = $this->get(route('refer.redeem.page', ['token' => $redeemToken]));

        $response->assertOk()
            ->assertSee('Reward redeemed')
            ->assertDontSee('Reward claimed')
            ->assertSee('Business')
            ->assertSee($business->name)
            ->assertSee('Confirmed')
            ->assertSeeText('Today, 1:42 AM')
            ->assertDontSee('5:42 AM')
            ->assertSee('$10 off your first visit')
            ->assertSee('Keep this screen as your receipt.');

        // The facts are labelled rows, not a centered stack:
        // each label is its own element beside its value.
        $this->assertStringContainsString('>Business<', $response->getContent());
        $this->assertStringContainsString('>Confirmed<', $response->getContent());
        // The outcome word carries the success-strong token.
        $this->assertMatchesRegularExpression('/text-success-strong[^>]*>\s*Reward redeemed/', $response->getContent());
    }

    public function test_claimed_receipt_shows_the_distinct_outcome_word_and_the_business_local_date_when_not_today(): void
    {
        $business = World::business(['timezone' => 'America/New_York']);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(2),
        ]);
        // 02:30 UTC on Oct 26 is 10:30 PM on Oct 25 in New
        // York: a different local date than today, so the full
        // local date and time render, never "Today".
        [, $claimToken] = World::reward($membership, $referral, [
            'state' => Reward::STATE_CLAIMED,
            'claimed_at' => Carbon::parse('2026-10-26 02:30:00', 'UTC'),
        ]);

        $response = $this->get(route('refer.claim.page', ['token' => $claimToken]));

        $response->assertOk()
            ->assertSee('Reward claimed')
            ->assertDontSee('Reward redeemed')
            ->assertSee('Business')
            ->assertSee($business->name)
            ->assertSee('Confirmed')
            ->assertSee('October 25, 2026')
            ->assertSee('10:30 PM')
            ->assertDontSee('Today,')
            ->assertDontSee('2:30 AM')
            ->assertSee('Keep this screen as your receipt.');
    }

    public function test_staff_redeemed_flood_matches_the_customer_receipt(): void
    {
        $business = World::business(['timezone' => 'America/New_York']);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $token] = World::referral($membership, $friend);

        $nonce = $this->staffNonce($token);
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $nonce])
            ->assertRedirect(route('redeem.show', ['token' => $token]));

        // The confirmation happened at the frozen instant:
        // 17:00 UTC is 1:00 PM in New York, today.
        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Reward redeemed')
            ->assertDontSee('Reward claimed')
            ->assertSee('Business')
            ->assertSee('Confirmed')
            ->assertSeeText('Today, 1:00 PM')
            ->assertSee('Show this screen if anyone asks.');
    }

    public function test_staff_claimed_flood_matches_the_customer_receipt(): void
    {
        $business = World::business(['timezone' => 'America/New_York']);
        $customer = World::customer('+12125550999', 'Reg', 'Ular', [
            'number_linked_at' => now()->subDays(8),
        ]);
        [$membership] = World::membership($business, $customer);
        $membership->forceFill(['created_at' => now()->subDays(30)])->save();
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(20),
        ]);
        [, $claimToken] = World::reward($membership, $referral);

        $nonce = $this->staffNonce($claimToken);
        $this->post(route('redeem.confirm', ['token' => $claimToken]), ['nonce' => $nonce])
            ->assertRedirect(route('redeem.show', ['token' => $claimToken]));

        $this->assertSame(Reward::STATE_CLAIMED, Reward::query()->firstOrFail()->state);

        $this->get(route('redeem.show', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Reward claimed')
            ->assertDontSee('Reward redeemed')
            ->assertSee('Business')
            ->assertSee('Confirmed')
            ->assertSeeText('Today, 1:00 PM')
            ->assertSee('Show this screen if anyone asks.');
    }
}
