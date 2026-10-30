<?php

namespace Tests\Feature\Owner;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\CanonicalEmail;
use App\Support\SignupSource;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\World;
use Tests\TestCase;

/**
 * SK-6 (Amendment 67; growth-marketing-plan.md Sections 4/6):
 * the optional "how did you hear about us" capture. Before this
 * build the only signup channels on the business row were the
 * acq_ UTM machine stamps (never surfaced) and claim_source
 * (the G1 claim channel only), so the G3 decal metric, the G1
 * claim-rate readouts, and every channel kill decision were
 * blind. Pins: SS-1 persist, SS-2 skip is identical to today,
 * SS-3 claim precedence, SS-4 write-once, SS-5 validation,
 * SS-6 the Friday readout surface (the admin console).
 */
class SignupSourceTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL_HEADING = 'How did you hear about us?';
    private const ADMIN_PASSWORD = 'admin password that is long enough 7';

    private static int $adminSequence = 0;

    private function finish(Business $business): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(World::owner($business))->get(route('onboarding.finish'));
    }

    private function saveSource(Business $business, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(World::owner($business))->post(route('onboarding.source'), $data);
    }

    private function adminSession(): array
    {
        self::$adminSequence++;
        $n = self::$adminSequence;

        $admin = User::create([
            'business_id' => null,
            'role' => 'platform_admin',
            'name' => 'Platform Admin',
            'email' => "sk6admin{$n}@example.test",
            'canonical_email' => CanonicalEmail::of("sk6admin{$n}@example.test"),
            'phone_e164' => '+121255508'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'password' => Hash::make(self::ADMIN_PASSWORD),
            'email_verified_at' => now(),
        ]);

        $service = app(TwoFactorService::class);
        $service->startEnrollment($admin, self::ADMIN_PASSWORD);
        $admin = $admin->fresh();
        $service->confirmEnrollment($admin, Totp::code((string) $admin->two_factor_secret), self::ADMIN_PASSWORD);

        return [$admin->fresh(), ['admin_auth.two_factor_passed' => true]];
    }

    // SS-1: a chosen source persists with its street tag and
    // capture timestamp, the audit row lands, and the panel
    // leaves the finish screen.
    public function test_chosen_source_persists_with_street_and_timestamp(): void
    {
        $business = World::business();

        $page = $this->finish($business);
        $page->assertOk();
        $page->assertSee(self::PANEL_HEADING);
        $page->assertSee('I saw a sign or poster in another shop');
        $page->assertSee('Someone from Easy Refer came into my shop');
        $page->assertSee('Street or area (optional)', false);

        $this->saveSource($business, [
            'signup_source' => 'decal_poster',
            'signup_source_detail' => 'Bay Street',
        ])->assertRedirect(route('onboarding.finish'));

        $fresh = $business->fresh();
        $this->assertSame('decal_poster', $fresh->signup_source);
        $this->assertSame('Bay Street', $fresh->signup_source_detail);
        $this->assertNotNull($fresh->signup_source_captured_at);
        $this->assertTrue(AuditLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'business.signup_source_recorded')
            ->exists());

        $this->finish($business)->assertDontSee(self::PANEL_HEADING);
        $this->assertSame('decal_poster', SignupSource::effectiveChannel($fresh));
    }

    // SS-2a: registration itself is untouched by the question:
    // a signup that never meets the panel completes exactly as
    // before, with a NULL source (no default, no backfill).
    public function test_signup_completes_identically_when_the_question_is_never_answered(): void
    {
        $this->post(route('register'), [
            'business_name' => 'Corner Deli',
            'name' => 'Dana Owner',
            'email' => 'dana-sk6@example.test',
            'phone' => '(212) 555-0147',
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
        ])->assertRedirect(route('onboarding.welcome'));

        $business = Business::query()->where('name', 'Corner Deli')->firstOrFail();
        $this->assertNull($business->signup_source);
        $this->assertNull($business->signup_source_detail);
        $this->assertNull($business->signup_source_captured_at);
        $this->assertNull(SignupSource::effectiveChannel($business));
        $this->assertSame('Not recorded', SignupSource::effectiveLabel($business));
    }

    // SS-2b: submitting the panel blank is a skip, not an
    // error: no validation failure, nothing stored, and the
    // finish screen still completes through its doors.
    public function test_blank_submit_is_a_skip_with_no_error_and_no_storage(): void
    {
        $business = World::business();

        $this->saveSource($business, ['signup_source' => '', 'signup_source_detail' => ''])
            ->assertRedirect(route('onboarding.finish'))
            ->assertSessionHasNoErrors();

        $this->assertNull($business->fresh()->signup_source);
        $this->assertNull($business->fresh()->signup_source_captured_at);

        $this->actingAs(World::owner($business))->post(route('onboarding.complete'), ['door' => 'dashboard'])
            ->assertRedirect(route('dashboard'));
        $this->assertNull($business->fresh()->signup_source);
    }

    // SS-3: claim precedence. A claimed business's
    // claim_source is the authoritative channel: the panel is
    // never offered, a direct submit stores nothing, and the
    // resolver reports the claim channel even over a
    // self-report planted on the row.
    public function test_claim_source_wins_over_the_self_reported_field(): void
    {
        $claimed = World::business(['claim_source' => 'claimable_page']);

        $this->finish($claimed)->assertDontSee(self::PANEL_HEADING);

        $this->saveSource($claimed, ['signup_source' => 'online'])
            ->assertRedirect(route('onboarding.finish'))
            ->assertSessionHasNoErrors();
        $this->assertNull($claimed->fresh()->signup_source);

        $planted = World::business([
            'claim_source' => 'claimable_page',
            'signup_source' => 'online',
            'signup_source_captured_at' => now(),
        ]);
        $this->assertSame('claimable_page', SignupSource::effectiveChannel($planted));
        $this->assertSame('Claimed their ready-made shop page', SignupSource::effectiveLabel($planted));
    }

    // SS-4: stored once. A second answer never overwrites the
    // first (the first touch is the channel truth, like the
    // acq_ first-touch rule).
    public function test_the_answer_is_written_once(): void
    {
        $business = World::business();

        $this->saveSource($business, ['signup_source' => 'demo'])->assertRedirect(route('onboarding.finish'));
        $this->saveSource($business, ['signup_source' => 'online', 'signup_source_detail' => 'Elsewhere'])
            ->assertRedirect(route('onboarding.finish'));

        $fresh = $business->fresh();
        $this->assertSame('demo', $fresh->signup_source);
        $this->assertNull($fresh->signup_source_detail);
    }

    // SS-5: a value outside the fixed set is refused with the
    // plain message and stores nothing; an over-long street
    // tag is refused the same way.
    public function test_values_outside_the_fixed_set_are_refused(): void
    {
        $business = World::business();

        $this->saveSource($business, ['signup_source' => 'carrier_pigeon'])
            ->assertSessionHasErrors(['signup_source' => 'Pick one of the choices, or leave it blank to skip.']);
        $this->assertNull($business->fresh()->signup_source);

        $this->saveSource($business, [
            'signup_source' => 'online',
            'signup_source_detail' => str_repeat('x', 121),
        ])->assertSessionHasErrors('signup_source_detail');
        $this->assertNull($business->fresh()->signup_source);
    }

    // SS-6: the Friday readout surface (growth plan Section 7
    // reads signup records): the admin businesses list shows
    // the effective channel and street, and the business
    // detail page shows the stored source, street, and date.
    public function test_the_admin_readout_shows_the_stored_source_and_street(): void
    {
        $business = World::business(['name' => 'Readout Barbers']);
        $this->saveSource($business, [
            'signup_source' => 'decal_poster',
            'signup_source_detail' => 'Bay Street',
        ]);

        [$admin, $session] = $this->adminSession();

        $this->actingAs($admin, 'admin')->withSession($session)
            ->get(route('admin.businesses'))
            ->assertOk()
            ->assertSee('How they heard')
            ->assertSee('Saw a decal or poster')
            ->assertSee('Bay Street');

        $this->actingAs($admin, 'admin')->withSession($session)
            ->get(route('admin.businesses.show', $business))
            ->assertOk()
            ->assertSee('How they heard about us')
            ->assertSee('Channel: Saw a decal or poster')
            ->assertSee('Street or area: Bay Street')
            ->assertSee('Recorded:');
    }
}
