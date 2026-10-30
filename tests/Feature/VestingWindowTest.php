<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use App\Support\RewardStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * F-5 (effectiveness audit, 2026-10-29): the bare claim link —
 * the page the reward text lands on, outside the referrer's own
 * verified session — showed the vesting state with NO time
 * window at all: "On its way" plus a text promise, a state
 * claim with no checkable value (STATE-BLIND COPY class).
 * G-17 keeps the exact date session-only; the window — the
 * days remaining on this reward's own vesting timestamp, or
 * the business's configured hold when no timestamp is stored —
 * renders on every vesting surface. These pins cover the bare
 * page in both directions, the helper's states, the owner
 * list's no-timestamp fallback, the share page's fallback, and
 * a source grep pin: every blade that renders the vesting pill
 * must also reference the window helper.
 */
class VestingWindowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Business, 1: Reward, 2: string} */
    private function claimSetup(array $rewardOverrides, array $businessOverrides = []): array
    {
        $business = World::business($businessOverrides);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        [$reward, $claimToken] = World::reward($membership, $referral, $rewardOverrides);

        return [$business, $reward, $claimToken];
    }

    public function test_bare_waiting_page_names_the_remaining_days_and_never_the_date(): void
    {
        [$business, $reward, $claimToken] = $this->claimSetup([
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(14),
            'notification_state' => 'queued',
        ]);

        $response = $this->get(route('refer.claim.page', ['token' => $claimToken]));

        $response->assertOk()
            ->assertSee('>On its way<', false)
            ->assertSee('Ready in about 14 days.')
            ->assertSee('We will text you when it is ready, unless you have opted out of texts. You can also check this page.')
            // G-17 stands: no exact date leaks to the bare link.
            ->assertDontSee($business->localTime($reward->available_at)->format('l, F j, Y'))
            ->assertDontSee('Your reward will be available on');
    }

    public function test_bare_waiting_page_tracks_the_actual_timestamp_not_a_hardcoded_window(): void
    {
        [, , $claimToken] = $this->claimSetup([
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(7),
            'notification_state' => 'queued',
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Ready in about 7 days.')
            ->assertDontSee('Ready in about 14 days.');

        [, , $claimToken] = $this->claimSetup([
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(10),
            'notification_state' => 'queued',
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Ready in about 10 days.');
    }

    public function test_bare_waiting_page_names_a_single_remaining_day_in_the_singular(): void
    {
        [, , $claimToken] = $this->claimSetup([
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addHours(20),
            'notification_state' => 'queued',
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Ready in about 1 day.')
            ->assertDontSee('Ready in about 1 days.');
    }

    public function test_available_reward_never_carries_vesting_language(): void
    {
        [, , $claimToken] = $this->claimSetup([
            'state' => Reward::STATE_AVAILABLE,
            'available_at' => now()->subDay(),
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertDontSee('Ready in about')
            ->assertDontSee('On its way');
    }

    public function test_held_reward_carries_no_vesting_window(): void
    {
        // A held reward's wait is a review, not a window: naming
        // vesting days would promise a date the review controls.
        [, , $claimToken] = $this->claimSetup([
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => now()->addDays(14),
            'hold' => true,
            'held_at' => now(),
        ]);

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Held for review')
            ->assertDontSee('Ready in about');
    }

    public function test_owner_rewards_list_names_the_window_when_no_timestamp_is_stored(): void
    {
        $business = World::business(['reward_vesting_days' => 7]);
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550984'));
        [$referral] = World::referral($membership, World::customer('+12125550112'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        World::reward($membership, $referral, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => null,
            'notification_state' => 'queued',
        ]);

        $this->actingAs($owner)->get(route('rewards.index'))
            ->assertOk()
            ->assertSee('On its way')
            ->assertSee('Ready in about 7 days.');
    }

    public function test_share_page_names_the_window_when_no_timestamp_is_stored(): void
    {
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();

        $business = World::business(['reward_vesting_days' => 7]);
        [, $qrToken] = World::qr($business);

        $carry = function ($response) {
            foreach ($response->headers->getCookies() as $cookie) {
                if ($cookie->getName() === 'er2_flow') {
                    $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
                }
            }
        };
        $carry($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));
        $membership = Membership::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        World::reward($membership, $referral, [
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => null,
            'notification_state' => 'queued',
        ]);

        $this->get(route('refer.share'))
            ->assertOk()
            ->assertSee('Your reward is on its way')
            ->assertSee('Ready in about 7 days.');
    }

    public function test_vesting_window_helper_states(): void
    {
        $business = World::business(['reward_vesting_days' => 7]);

        $noTimestamp = new Reward;
        $noTimestamp->forceFill([
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => null,
            'created_at' => now(),
        ]);
        $noTimestamp->setRelation('business', $business);
        // No stored timestamp: the configured hold is the window.
        $this->assertSame('Ready in about 7 days.', RewardStatus::vestingWindow($noTimestamp));
        // The owner helper falls back to the same window.
        $this->assertSame('Ready in about 7 days.', RewardStatus::readyOn($noTimestamp));

        $outOfRange = World::business(['reward_vesting_days' => 30]);
        $clamped = new Reward;
        $clamped->forceFill([
            'state' => Reward::STATE_PENDING_VESTING,
            'available_at' => null,
            'created_at' => now(),
        ]);
        $clamped->setRelation('business', $outOfRange);
        // Out-of-range stored config clamps to the OD-3 default,
        // exactly as RedemptionService mints it.
        $this->assertSame('Ready in about 14 days.', RewardStatus::vestingWindow($clamped));

        foreach ([Reward::STATE_AVAILABLE, Reward::STATE_CLAIMED, Reward::STATE_DENIED, Reward::STATE_EXPIRED] as $state) {
            $other = new Reward;
            $other->forceFill([
                'state' => $state,
                'available_at' => now()->addDays(14),
                'created_at' => now(),
            ]);
            $other->setRelation('business', $business);
            $this->assertNull(RewardStatus::vestingWindow($other), "state {$state} must never carry vesting language");
        }

        $held = new Reward;
        $held->forceFill([
            'state' => Reward::STATE_PENDING_VESTING,
            'hold' => true,
            'available_at' => now()->addDays(14),
            'created_at' => now(),
        ]);
        $held->setRelation('business', $business);
        $this->assertNull(RewardStatus::vestingWindow($held));
        $this->assertNull(RewardStatus::readyOn($held));
    }

    public function test_every_vesting_pill_blade_references_the_window(): void
    {
        // Grep pin (both directions): a blade that renders the
        // vesting pill must, in the same file, reference the
        // window helper or a stored-date render — the bare
        // windowless vesting string cannot come back file by file.
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        $found = 0;
        foreach ($views as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (! str_contains($source, 'on_its_way')) {
                continue;
            }
            $found++;
            $this->assertTrue(
                str_contains($source, 'vestingWindow') || str_contains($source, 'readyOn'),
                $file->getPathname().' renders the vesting pill with no window/date reference',
            );
        }
        $this->assertGreaterThanOrEqual(3, $found, 'the grep pin must actually cover the vesting blades');
    }
}
