<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Referral\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * F-6 (effectiveness audit, 2026-10-29): the enroll form and
 * the friend-claim form REQUIRED a last name that no surface
 * the customer ever sees actually uses — staff confirm reads
 * first name only. The last name is now optional on both forms
 * (collected and kept when given; a blank submission means
 * "not given" and never erases a stored surname), and every
 * surface that composes first + last renders through
 * Customer::fullName(), the trimmed composer, so a missing
 * last name can never render "Sam " with a trailing space,
 * a double space, or an empty greeting token.
 *
 * Surface census behind these pins:
 * - flow/landing + flow/friend forms: optional (these pins).
 * - Share payload + friend receipt prime: fullName() already.
 * - Staff confirm (staff/referral, staff/reward): first name
 *   only already — pinned here via the redeem page's "For Sam".
 * - Texts: friend-link SMS uses first name only; the share
 *   payload uses fullName(). No email composes a customer name.
 * - Owner lists/details that composed first + last with a
 *   literal space (customers index/show/edit form, rewards
 *   index, campaigns recipient list, dashboard top referrers)
 *   now render fullName() — pinned here on the reachable pages
 *   plus a source grep pin covering every blade.
 * - No surface genuinely needs a surname: the owner-add form
 *   already allowed it blank, and the owner's own identity is
 *   the User record, not the customer name.
 */
class OptionalLastNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    public function test_enroll_form_marks_last_name_optional_and_accepts_its_absence(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $landing = $this->get(route('refer.landing', ['token' => $qrToken]))->assertOk();
        $landing->assertSee('Last name (optional)');
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="last_name"[^>]*>/',
            $landing->getContent(),
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="last_name"[^>]*\brequired\b/',
            $landing->getContent(),
            'the enroll last_name input must not be required',
        );
        $this->carryFlowCookie($landing);

        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Sam',
            'phone' => '2125550111',
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $this->carryFlowCookie($this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share')));

        $customer = Customer::query()->where('phone_e164', '+12125550111')->firstOrFail();
        $this->assertSame('Sam', $customer->first_name);
        $this->assertSame('', $customer->last_name);
        $this->assertSame('Sam', $customer->fullName());

        // The share page greets and composes the share payload
        // with the single name: no trailing or double space.
        $share = $this->get(route('refer.share'))->assertOk();
        $share->assertSee('You are in, Sam');
        $share->assertSee('Sam invited you');
        $this->assertDoesNotMatchRegularExpression('/Sam {2,}|Sam (?=<)/', $share->getContent());
    }

    public function test_enroll_still_collects_a_given_last_name(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Sam',
            'last_name' => 'Reed',
            'phone' => '2125550111',
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->carryFlowCookie($this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share')));

        $customer = Customer::query()->where('phone_e164', '+12125550111')->firstOrFail();
        $this->assertSame('Reed', $customer->last_name);
        $this->assertSame('Sam Reed', $customer->fullName());

        $share = $this->get(route('refer.share'))->assertOk();
        $share->assertSee('Sam Reed invited you');
    }

    public function test_friend_claim_form_marks_last_name_optional_and_accepts_its_absence(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership, $shareToken] = World::membership($business, $referrer);
        // Age the anchor past the 25-minute claim gap so the
        // claim completes in one pass.
        $membership->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        $friend = $this->get(route('refer.friend', ['token' => $shareToken]))->assertOk();
        $friend->assertSee('Last name (optional)');
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="last_name"[^>]*\brequired\b/',
            $friend->getContent(),
            'the claim last_name input must not be required',
        );
        $this->carryFlowCookie($friend);

        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo',
            'phone' => '2125550212',
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect();

        $customer = Customer::query()->where('phone_e164', '+12125550212')->firstOrFail();
        $this->assertSame('Jo', $customer->first_name);
        $this->assertSame('', $customer->last_name);

        $referral = Referral::query()->where('referred_customer_id', $customer->id)->firstOrFail();

        // Staff confirm reads first name only: "For Jo", clean.
        $staff = $this->get(route('redeem.show', ['token' => $referral->redeem_token_encrypted]))->assertOk();
        $staff->assertSee('For Jo');
        $this->assertDoesNotMatchRegularExpression('/For Jo {2,}|For Jo (?=<)/', $staff->getContent());
    }

    public function test_blank_last_name_never_erases_a_stored_one(): void
    {
        $business = World::business();
        $existing = World::customer('+12125550111', 'Sam', 'Reed');
        World::membership($business, $existing);

        $done = app(EnrollmentService::class)->completeEnrollment($business, [
            'first_name' => 'Sam',
            'last_name' => '',
            'phone_e164' => '+12125550111',
            'email' => null,
            'consent' => true,
            'scan_event_pending' => false,
        ], '127.0.0.1', null, false);

        $this->assertSame('Reed', $done['customer']->fresh()->last_name);
    }

    public function test_single_name_rendering_on_owner_surfaces(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $sam = World::customer('+12125550111', 'Sam', '');
        [$membership] = World::membership($business, $sam);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
        World::reward($membership, $referral, ['state' => Reward::STATE_AVAILABLE]);

        $index = $this->actingAs($owner)->get(route('customers.index'))->assertOk();
        $index->assertSee('>Sam</a>', false);
        $this->assertDoesNotMatchRegularExpression('/Sam {2,}|Sam (?=<)/', $index->getContent());

        $show = $this->actingAs($owner)->get(route('customers.show', $membership))->assertOk();
        $show->assertSee('Remove Sam from your store?');
        $show->assertDontSee('Remove Sam  from');
        $this->assertDoesNotMatchRegularExpression('/Sam {2,}|Sam (?=<)/', $show->getContent());

        $edit = $this->actingAs($owner)->get(route('customers.edit', $membership))->assertOk();
        $edit->assertSee('>Sam</h2>', false);
        $this->assertDoesNotMatchRegularExpression('/Sam {2,}|Sam (?=<)/', $edit->getContent());

        $rewards = $this->actingAs($owner)->get(route('rewards.index'))->assertOk();
        $rewards->assertSee('Sam');
        $rewards->assertDontSee('Sam  ·');
        $this->assertDoesNotMatchRegularExpression('/Sam {2,}|Sam (?=<)/', $rewards->getContent());
    }

    public function test_no_blade_composes_first_and_last_with_a_literal_space(): void
    {
        // Grep pin: the double-space class cannot come back file
        // by file. Every blade name render goes through
        // fullName() (trimmed) or first name alone; the only PHP
        // first.' '.last compositions left are inside trim(...)
        // or fullName() itself.
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($views as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression(
                '/first_name\s*\}\}\s*\{\{[^}]*last_name/',
                $source,
                $file->getPathname().' composes first + last with a literal space',
            );
            $this->assertStringNotContainsString(
                "first_name.' '.",
                $source,
                $file->getPathname().' composes first + last in a string concat',
            );
        }

        $app = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($app as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            foreach (file($file->getPathname()) as $line) {
                if (str_contains($line, "first_name.' '") || str_contains($line, 'first_name." "')) {
                    $this->assertTrue(
                        str_contains($line, 'trim(') || str_contains($file->getPathname(), 'Customer.php'),
                        $file->getPathname().' composes first + last untrimmed: '.trim($line),
                    );
                }
            }
        }
    }
}
