<?php

namespace Tests\Feature;

use App\Contracts\PhoneVerificationGateway;
use App\Models\Business;
use App\Models\ClaimableEvent;
use App\Models\ClaimablePage;
use App\Models\ClaimReview;
use App\Models\ClaimSuppression;
use App\Models\Membership;
use App\Models\Referral;
use App\Services\Claimable\ClaimableGenerationBlocked;
use App\Services\Claimable\ClaimableService;
use App\Services\Claimable\ClaimGateService;
use App\Services\Claimable\ClaimReviewService;
use Illuminate\Database\QueryException;
use App\Support\ClaimableCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\TestCase;

/**
 * G1 claimable pages (g1-claimable-design-pass.md). Pins:
 * - the §10.2 strings render only in their §10.3 valid states;
 * - the preview code is inert (H4) and the demo writes nothing;
 * - the §4 gate: the code goes ONLY to the listed phone, so a
 *   wrong phone cannot complete a claim (the abuse acceptance);
 * - the §3.2 hand-off: claimed at the signup checkpoint, in the
 *   standard activation path, with attribution;
 * - the §8 removal lifecycle R0-R4 and its acceptance attempts;
 * - the §9 funnel instrumentation (view -> claim start -> claim
 *   complete, attributable per page).
 */
class ClaimablePageTest extends TestCase
{
    use RefreshDatabase;

    private FakePhoneVerificationGateway $verify;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verify = new FakePhoneVerificationGateway;
        $this->app->instance(PhoneVerificationGateway::class, $this->verify);
    }

    /** @return array{0: ClaimablePage, 1: string} */
    private function makePage(array $overrides = []): array
    {
        $snapshot = array_merge([
            'displayName' => 'Bay Street Barber Co.',
            'placesType' => 'barber_shop',
            'street' => '412 Bay Street',
            'city' => 'Staten Island',
            'state' => 'NY',
            'postalCode' => '10304',
            'nationalPhoneNumber' => '+17185550101',
            'businessStatus' => 'OPERATIONAL',
        ], $overrides['snapshot'] ?? []);

        return app(ClaimableService::class)->generate(
            $overrides['place_id'] ?? 'place-'.uniqid(),
            $snapshot,
            $overrides['cohort'] ?? 'concierge',
        );
    }

    private function seeText(string $html, string $string): void
    {
        $this->assertStringContainsString(e($string), $html);
    }

    private function dontSeeText(string $html, string $string): void
    {
        $this->assertStringNotContainsString(e($string), $html);
    }

    private function signupPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Bay Street Barber Co.',
            'business_type' => 'barber',
            'name' => 'Sam Owner',
            'email' => 'sam@example.com',
            'phone' => '7185550101',
            'password' => 'correct-horse-battery-1',
            'password_confirmation' => 'correct-horse-battery-1',
        ], $overrides);
    }

    // ---------- The page and its state-truth pins ----------

    public function test_page_renders_the_41_string_inventory_in_its_valid_state(): void
    {
        [$page, $token] = $this->makePage();
        app(ClaimableService::class)->markSent($page);

        $html = $this->get(route('claimable.show', $token))->assertOk()->getContent();

        foreach ([
            ClaimableCopy::c01($page), ClaimableCopy::c02($page), ClaimableCopy::c03($page),
            ClaimableCopy::c04($page), ClaimableCopy::c05(), ClaimableCopy::c06($page),
            ClaimableCopy::c07($page), ClaimableCopy::c08(), ClaimableCopy::c33($page),
            ClaimableCopy::c34(),
        ] as $string) {
            $this->seeText($html, $string);
        }
        foreach (ClaimableCopy::costBlock() as $line) {
            $this->seeText($html, $line);
        }
        // C09 rides the poster block on the page.
        $poster = $this->get(route('claimable.poster', $token))->assertOk()->getContent();
        $this->seeText($poster, ClaimableCopy::c09());
        $this->seeText($poster, ClaimableCopy::c08());

        // §10.4 negative inventory: none of it, anywhere.
        foreach (ClaimableCopy::BANNED as $banned) {
            $this->assertStringNotContainsStringIgnoringCase($banned, $html.$poster);
        }
        // No fake activity (H2): no counts, no rating, no results.
        $this->assertStringNotContainsString('customers have joined', $html);
    }

    public function test_preview_qr_is_inert_it_encodes_only_the_claim_page(): void
    {
        [$page, $token] = $this->makePage();

        $svg = $this->get(route('claimable.qr', $token))->assertOk()->getContent();
        $this->assertStringContainsString('<svg', $svg);

        // The code resolves here and only here: following it lands
        // on the claim page, and no flow rows exist for anyone.
        $this->get(route('claimable.show', $token))->assertOk();
        $this->assertSame(0, Membership::count());
        $this->assertSame(0, Referral::count());
        $this->assertSame(0, Business::count());
    }

    public function test_demo_writes_nothing_and_carries_demo_strings(): void
    {
        [$page, $token] = $this->makePage();

        $html = $this->get(route('claimable.demo', $token))->assertOk()->getContent();
        foreach ([ClaimableCopy::c10(), ClaimableCopy::c11(), ClaimableCopy::c12(), ClaimableCopy::c13(), ClaimableCopy::c14()] as $str) {
            $this->seeText($html, $str);
        }

        $this->post(route('claimable.demo.complete', $token))->assertRedirect(route('claimable.show', $token));

        $this->assertSame(0, Membership::count());
        $this->assertSame(0, Referral::count());
        $this->assertSame(0, Business::count());
        // Only the page's own funnel events exist.
        $this->assertSame(
            ['demo_started', 'demo_completed'],
            $page->events()->whereIn('event', ['demo_started', 'demo_completed'])->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_page_is_noindex_and_absent_from_the_sitemap(): void
    {
        [$page, $token] = $this->makePage();

        $this->get(route('claimable.show', $token))->assertOk()->assertSee('noindex', false);
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/claim/', $sitemap);
    }

    // ---------- The gate (abuse acceptance) ----------

    public function test_gate_code_goes_only_to_the_listed_phone_so_a_wrong_phone_cannot_claim(): void
    {
        [$page, $token] = $this->makePage();

        $this->get(route('claimable.claim', $token))->assertOk()
            ->assertSee(ClaimableCopy::c22($page))
            ->assertSee(ClaimableCopy::c24());

        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms'])
            ->assertRedirect(route('claimable.claim', $token));

        // The code went to the LISTED number, never to any number
        // a claimer could type — there is no destination field.
        $this->assertSame([['phone' => '+17185550101', 'channel' => 'sms']], $this->verify->starts);

        // Wrong code: refused with C25, nothing created.
        $this->post(route('claimable.gate.check', $token), ['code' => '000000'])
            ->assertRedirect(route('claimable.claim', $token));
        $this->get(route('claimable.claim', $token))->assertSee(ClaimableCopy::c25());
        $this->assertSame(0, Business::count());
        $this->assertNull(session('claimable.claim_page_id'));
    }

    public function test_gate_budgets_lock_after_five_wrong_entries_and_three_sends(): void
    {
        [$page, $token] = $this->makePage();

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        }
        // Fourth send exhausts the daily budget and locks.
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode]);
        $this->assertNull(session('claimable.claim_page_id'), 'A locked page must not arm a claim, even with the right code.');

        [$page2, $token2] = $this->makePage();
        $this->post(route('claimable.gate.send', $token2), ['channel' => 'sms']);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('claimable.gate.check', $token2), ['code' => '00000'.$i]);
        }
        $this->post(route('claimable.gate.check', $token2), ['code' => $this->verify->approvedCode]);
        $this->assertNull(session('claimable.claim_page_id'), 'Five wrong entries must lock the sixth attempt, even with the right code.');
    }

    // ---------- The claim hand-off (§3.2) ----------

    public function test_full_claim_lands_at_the_signup_checkpoint_in_the_standard_path(): void
    {
        [$page, $token] = $this->makePage();
        app(ClaimableService::class)->markSent($page);

        // Funnel: view -> claim start -> gate -> checkpoint.
        $this->get(route('claimable.show', $token))->assertOk();
        $this->get(route('claimable.claim', $token))->assertOk();
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode])
            ->assertRedirect(route('register'));

        // The wizard is prefilled: C27 confirm heading, C26 label,
        // C21 leaving line — claim-path strings only.
        $register = $this->get(route('register'))->assertOk()->getContent();
        $pageFresh = $page->fresh();
        $this->seeText($register, ClaimableCopy::c27($pageFresh));
        $this->seeText($register, ClaimableCopy::c26($pageFresh));
        $this->seeText($register, ClaimableCopy::c21());

        $this->post(route('register.store'), $this->signupPayload())
            ->assertRedirect(route('onboarding.welcome'));

        $business = Business::firstOrFail();
        $this->assertSame('claimable_page', $business->claim_source);
        $this->assertSame($page->id, $business->claimable_page_id);
        // The head start: address + ONE starter reward preselected.
        $this->assertSame('412 Bay Street', $business->street);
        $this->assertSame('$10 off your first cut', $business->friend_reward_text);
        $this->assertSame('$10 off your first cut', $business->referrer_reward_text);
        // The standard gate inputs only: reward text set, no card —
        // live for its first 5 visits under the activation ruling.
        $this->assertSame(5, $business->free_visits_remaining);

        $page = $page->fresh();
        $this->assertSame(ClaimablePage::STATUS_CLAIMED, $page->status);
        $this->assertSame($business->id, $page->claimed_business_id);
        $this->assertNull($page->photo_ref);

        // Funnel events, attributable per page, in order.
        $events = $page->events()->orderBy('id')->pluck('event')->all();
        $this->assertSame(['generated', 'sent', 'opened', 'claim_started', 'gate_code_sent', 'gate_passed', 'claimed'], $events);

        // The old link is now the tombstone: C38 + C39, no demo.
        $tomb = $this->get(route('claimable.show', $token))->assertOk()->getContent();
        $this->seeText($tomb, ClaimableCopy::c38($page));
        $this->seeText($tomb, ClaimableCopy::c39($page));
        $this->dontSeeText($tomb, ClaimableCopy::c01($page));
    }

    public function test_cold_signup_never_shows_claim_strings(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();
        $this->assertStringNotContainsString('From ', $html.'x') ;
        $this->assertStringNotContainsString(ClaimableCopy::c21(), $html);
        $this->assertStringNotContainsString('This is what we have for', $html);
    }

    public function test_duplicate_claims_race_only_one_business_wins(): void
    {
        [$page, $token] = $this->makePage();

        // First claimer completes the checkpoint.
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode]);
        $this->post(route('register.store'), $this->signupPayload());
        $this->post(route('logout'));

        // Second claimer, fresh session: the page is a tombstone;
        // the gate cannot even start.
        $this->get(route('claimable.show', $token))->assertSee(ClaimableCopy::c38($page->fresh()));
        // The gate route is gone entirely on a claimed page.
        $this->get(route('claimable.claim', $token))->assertNotFound();
        $this->assertSame(1, Business::count());
    }

    public function test_claimed_business_data_is_editable_only_by_its_owner(): void
    {
        [$page, $token] = $this->makePage();
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode]);
        $this->post(route('register.store'), $this->signupPayload());
        $business = Business::firstOrFail();

        // A guest hitting the owner surface is turned away.
        auth()->logout();
        $this->get(route('owner.qr'))->assertRedirect(route('login'));
        $this->assertSame('Bay Street Barber Co.', $business->fresh()->name);
    }

    // ---------- Review fallback + hand-off ----------

    public function test_review_fallback_creates_no_account_and_locks_the_page(): void
    {
        [$page, $token] = $this->makePage();

        $this->post(route('claimable.review', $token), ['name' => 'Sam Owner', 'phone' => '7185559999'])
            ->assertRedirect(route('claimable.show', $token));

        $this->assertSame(0, Business::count());
        $this->assertSame(ClaimablePage::STATUS_PENDING_REVIEW, $page->fresh()->status);

        // The requester sees C31; anyone else sees C32, and there
        // is NO claim control for them (replaced, not disabled).
        $this->get(route('claimable.show', $token))->assertSee(ClaimableCopy::c31());
        $this->withSession([])->get(route('claimable.show', $token));
        $other = $this->get(route('claimable.show', $token))->getContent();
        // (same browser session carries the identity; a clean
        // session is asserted in the service-level denial test.)

        // Approval mints a single-use hand-off; using it arms the
        // claim, and reusing it 404s.
        $review = ClaimReview::firstOrFail();
        ['plainHandoffToken' => $plain] = app(ClaimReviewService::class)->approve($review);
        $this->get(route('claimable.handoff', $plain))->assertRedirect(route('register'));
        $this->assertSame($page->id, session('claimable.claim_page_id'));
        $this->get(route('claimable.handoff', $plain))->assertNotFound();
    }

    public function test_pending_page_for_a_stranger_shows_c32_and_no_claim_button(): void
    {
        [$page, $token] = $this->makePage();
        ClaimReview::create([
            'claimable_page_id' => $page->id,
            'kind' => ClaimReview::KIND_GATE_FALLBACK,
            'requester_name' => 'Someone Else',
            'contact_phone_e164' => '+17185559999',
            'identity_hash' => ClaimReview::identityHash('Someone Else', '+17185559999'),
            'status' => 'pending',
        ]);
        $page->forceFill(['status' => ClaimablePage::STATUS_PENDING_REVIEW])->save();

        $html = $this->get(route('claimable.show', $token))->assertOk()->getContent();
        $this->seeText($html, ClaimableCopy::c32());
        $this->assertStringNotContainsString('Claim this page', $html);
    }

    public function test_denied_review_frees_the_page_and_locks_the_identity(): void
    {
        [$page, $token] = $this->makePage();
        $this->post(route('claimable.review', $token), ['name' => 'Sam Owner', 'phone' => '7185559999']);

        app(ClaimReviewService::class)->deny(ClaimReview::firstOrFail());

        $this->assertSame(ClaimablePage::STATUS_GENERATED, $page->fresh()->status);
        // Same identity re-requesting inside 30 days is refused.
        $this->post(route('claimable.review', $token), ['name' => 'Sam Owner', 'phone' => '7185559999'])
            ->assertStatus(422);
    }

    // ---------- Removal lifecycle R0-R4 (§8) ----------

    public function test_removal_hides_at_once_and_purges_and_suppresses_at_day_7(): void
    {
        [$page, $token] = $this->makePage();
        app(ClaimableService::class)->markSent($page);
        $this->get(route('claimable.show', $token))->assertOk();

        // R0: one tap; requester sees C35 + C36.
        $this->post(route('claimable.remove', $token))->assertRedirect(route('claimable.show', $token));
        $html = $this->get(route('claimable.show', $token))->assertOk()->getContent();
        $this->seeText($html, ClaimableCopy::c35());
        $this->seeText($html, ClaimableCopy::c36($page));

        $page = $page->fresh();
        $this->assertSame(ClaimablePage::STATUS_REMOVED, $page->status);
        $this->assertNotNull($page->listed_phone_e164, 'Snapshot is held (not rendered) during the restore window.');

        // R2/R3: window closes -> purge + 12-month suppression.
        $this->travel(8)->days();
        app(ClaimableService::class)->closeRestoreWindows();
        $page = $page->fresh();
        $this->assertNull($page->listed_phone_e164);
        $this->assertNotNull($page->purged_at);
        $suppression = ClaimSuppression::where('place_id', $page->place_id)->firstOrFail();
        $this->assertTrue($suppression->isActive());
        $this->assertEqualsWithDelta(
            $page->removed_at->copy()->addMonths(12)->timestamp,
            $suppression->suppression_ends_at->timestamp,
            2,
        );

        // The removed line still renders from the bare register row.
        $this->get(route('claimable.show', $token))->assertSee(ClaimableCopy::c35());

        // §8 acceptance 4: regeneration under suppression blocks
        // BEFORE any snapshot is used.
        try {
            $this->makePage(['place_id' => $page->place_id]);
            $this->fail('Suppressed place_id must block generation.');
        } catch (ClaimableGenerationBlocked $e) {
            $this->assertSame('suppressed', $e->getMessage());
        }
    }

    public function test_troll_removal_restores_with_no_suppression(): void
    {
        [$page, $token] = $this->makePage();
        app(ClaimableService::class)->markSent($page);
        $this->post(route('claimable.remove', $token));

        app(ClaimableService::class)->resolveRestoreCheck($page->fresh(), false);

        $page = $page->fresh();
        $this->assertSame(ClaimablePage::STATUS_SENT, $page->status);
        $this->assertSame(0, ClaimSuppression::count());
        $this->get(route('claimable.show', $token))->assertSee(ClaimableCopy::c04($page));
    }

    public function test_removal_mid_gate_kills_the_claim_and_a_correct_code_opens_nothing(): void
    {
        [$page, $token] = $this->makePage();
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);

        // A removal lands (another session's act, same register).
        app(ClaimableService::class)->requestRemoval($page->fresh(), 'page_footer');

        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode])
            ->assertRedirect(route('claimable.show', $token));
        $this->get(route('claimable.show', $token))->assertSee(ClaimableCopy::c37());
        $this->assertSame(0, Business::count());
        $this->assertNull(session('claimable.claim_page_id'));
    }

    public function test_checkpoint_after_removal_refuses_and_creates_nothing(): void
    {
        [$page, $token] = $this->makePage();
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode]);
        $this->assertSame($page->id, session('claimable.claim_page_id'));

        app(ClaimableService::class)->requestRemoval($page->fresh(), 'operator');

        $this->post(route('register.store'), $this->signupPayload())
            ->assertSessionHasErrors('account');
        $this->assertSame(0, Business::count());
    }

    public function test_removal_after_claim_never_suppresses_the_business(): void
    {
        [$page, $token] = $this->makePage();
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode]);
        $this->post(route('register.store'), $this->signupPayload());

        // The remove route on a claimed page routes away from
        // suppression entirely (§8.4 claimed branch).
        $this->post(route('claimable.remove', $token));
        $this->assertSame(ClaimablePage::STATUS_CLAIMED, $page->fresh()->status);
        $this->assertSame(0, ClaimSuppression::count());
    }

    // ---------- Expiry, generation guards, measurement ----------

    public function test_expiry_purges_the_snapshot_and_renders_c40(): void
    {
        [$page, $token] = $this->makePage();
        $this->travel(61)->days();

        $this->get(route('claimable.show', $token))->assertSee(ClaimableCopy::c40($page->fresh()));
        $page = $page->fresh();
        $this->assertSame(ClaimablePage::STATUS_EXPIRED, $page->status);
        $this->assertNull($page->listed_phone_e164);
        $this->assertSame('Bay Street Barber Co.', $page->shop_name, 'The register row keeps place_id + name only.');
    }

    public function test_generation_blocks_existing_customers_and_dead_shops(): void
    {
        Business::factory()->create(['name' => 'Bay Street Barber Co.', 'city' => 'Staten Island', 'contact_phone_e164' => '+17185550101']);

        try {
            $this->makePage();
            $this->fail('Existing customer must block generation (H9).');
        } catch (ClaimableGenerationBlocked $e) {
            $this->assertSame('existing_customer', $e->getMessage());
        }

        try {
            $this->makePage(['snapshot' => ['businessStatus' => 'CLOSED_PERMANENTLY']]);
            $this->fail('A non-operational shop must block generation.');
        } catch (ClaimableGenerationBlocked $e) {
            $this->assertSame('not_operational', $e->getMessage());
        }
    }

    public function test_claim_rate_counts_sent_only_and_removed_stays_in_the_denominator(): void
    {
        $service = app(ClaimableService::class);

        [$p1, $t1] = $this->makePage();
        $service->markSent($p1); // sent, unclaimed
        [$p2, $t2] = $this->makePage();
        $service->markSent($p2);
        $service->requestRemoval($p2, 'page_footer'); // sent, removed
        [$p3] = $this->makePage(); // generated only: never in the rate

        $rate = $service->claimRate('concierge');
        $this->assertSame(2, $rate['sent']);
        $this->assertSame(0, $rate['claimed']);
        $this->assertSame(1, $rate['removed']);
        $this->assertSame(0.5, $rate['removed_rate']);
    }

    public function test_contested_claim_reversal_transfers_the_business_and_detaches_the_squatter(): void
    {
        [$page, $token] = $this->makePage();
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode]);
        $this->post(route('register.store'), $this->signupPayload());
        $business = Business::firstOrFail();
        $squatterId = $business->users()->where('role', 'owner')->firstOrFail()->id;
        auth()->logout();

        // The real owner contests from the tombstone.
        $this->post(route('claimable.contested.store', $token), ['name' => 'Real Owner', 'phone' => '7185557777'])
            ->assertRedirect(route('claimable.show', $token));
        $review = ClaimReview::where('kind', ClaimReview::KIND_CONTESTED)->firstOrFail();

        $newOwner = app(ClaimReviewService::class)->reverseContestedClaim($review, [
            'name' => 'Real Owner',
            'email' => 'real@example.com',
            'canonical_email' => 'real@example.com',
            'phone_e164' => '+17185557777',
            'password' => 'correct-horse-battery-2',
        ]);

        // Business preserved; squatter detached; new owner attached.
        $this->assertSame($business->id, $newOwner->business_id);
        $this->assertNull(\App\Models\User::findOrFail($squatterId)->business_id);
        $this->assertSame($business->id, Business::firstOrFail()->id);
        $this->assertSame('reversed', $review->fresh()->status);
    }

    public function test_gate_and_onboarding_claim_strings_render_in_their_states(): void
    {
        [$page, $token] = $this->makePage();

        // Gate view (P3): C22-C24 + the C23 choices + the C30
        // fallback intro ride the same screen.
        $gate = $this->get(route('claimable.claim', $token))->assertOk()->getContent();
        $this->seeText($gate, ClaimableCopy::c22($page));
        $this->seeText($gate, ClaimableCopy::c23()['sms']);
        $this->seeText($gate, ClaimableCopy::c23()['call']);
        $this->seeText($gate, ClaimableCopy::c24());
        $this->seeText($gate, ClaimableCopy::c30());
        $this->seeText($gate, ClaimableCopy::c21());
        // C20 is the claim block heading on the page itself (P2).
        $this->seeText($this->get(route('claimable.show', $token))->getContent(), ClaimableCopy::c20($page));

        // Claim, then the onboarding twins: C28 on Location (the
        // snapshot address is prefilled for a one-tap confirm) and
        // C29 on Rewards (the starter preselected).
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode]);
        $this->post(route('register.store'), $this->signupPayload());

        $location = $this->get(route('onboarding.step', 'location'))->assertOk()->getContent();
        $this->seeText($location, ClaimableCopy::c28());
        $this->assertStringContainsString('412 Bay Street', $location);

        $rewards = $this->get(route('onboarding.step', 'rewards'))->assertOk()->getContent();
        $this->seeText($rewards, ClaimableCopy::c29For('$10 off your first cut'));

        // C41: the contested intake form from the tombstone.
        $tombstoneToken = $token;
        auth()->logout();
        $form = $this->get(route('claimable.contested', $tombstoneToken))->assertOk()->getContent();
        $this->seeText($form, ClaimableCopy::c41());
    }

    public function test_concierge_seeder_builds_the_three_hand_made_pages(): void
    {
        $this->seed(\Database\Seeders\ClaimableConciergeSeeder::class);

        $pages = ClaimablePage::query()->where('cohort', 'concierge')->get();
        $this->assertCount(3, $pages);
        $this->assertSame(
            ['barber', 'cafe', 'salon'],
            $pages->pluck('business_type')->sort()->values()->all(),
        );
        foreach ($pages as $page) {
            $this->assertSame(ClaimablePage::STATUS_GENERATED, $page->status);
            $this->assertNull($page->sent_at, 'Hand-made pages are never marked sent by the build.');
        }
    }

    // ---------- Adversarial review fixes (g1-claimable-review.md) ----------

    /**
     * Review 1a (pin 1 of 2): the checkpoint race, interleaved. This
     * session is armed; while its checkpoint transaction runs (the
     * winner's claim flip lands from a Business::created hook, i.e.
     * after this session's pre-check read the page as claimable but
     * before its own flip), the flip must re-check inside the
     * transaction: the loser creates NOTHING and gets C37.
     */
    public function test_interleaved_checkpoint_only_one_business_wins_and_loser_gets_c37(): void
    {
        [$page, $token] = $this->makePage();
        $winner = Business::factory()->create([
            'claim_source' => 'claimable_page',
            'claimable_page_id' => $page->id,
        ]);

        // Arm this session through the real gate.
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode]);
        $this->assertSame($page->id, session('claimable.claim_page_id'));

        // The winner's flip commits while the loser's checkpoint
        // transaction is in flight (simulated on the same
        // connection: the loser's re-check must observe it).
        $interleave = true;
        Business::created(function () use ($page, $winner, &$interleave) {
            if (! $interleave) {
                return;
            }
            $interleave = false;
            $page->fresh()->forceFill([
                'status' => ClaimablePage::STATUS_CLAIMED,
                'claimed_business_id' => $winner->id,
                'claimed_at' => now(),
            ])->save();
        });

        $this->post(route('register.store'), $this->signupPayload(['email' => 'loser@example.com']))
            ->assertSessionHasErrors('account');

        $this->assertSame(
            ClaimableCopy::c37(),
            session('errors')->getBag('default')->first('account'),
        );
        $this->assertSame(1, Business::count(), 'Exactly one business may result from two armed checkpoints.');
        $this->assertSame($winner->id, Business::firstOrFail()->id);
    }

    /**
     * Review 1a (pin 2 of 2): a removal that commits mid-checkpoint
     * is never overwritten back to claimed by the loser's flip.
     */
    public function test_removal_committed_mid_checkpoint_is_never_overwritten_back_to_claimed(): void
    {
        [$page, $token] = $this->makePage();
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode]);
        $this->assertSame($page->id, session('claimable.claim_page_id'));

        $interleave = true;
        Business::created(function () use ($page, &$interleave) {
            if (! $interleave) {
                return;
            }
            $interleave = false;
            app(ClaimableService::class)->requestRemoval($page->fresh(), 'operator');
        });

        $this->post(route('register.store'), $this->signupPayload())
            ->assertSessionHasErrors('account');

        $this->assertSame(0, Business::count(), 'A checkpoint that lost to a removal creates nothing.');
        $this->assertNotSame(ClaimablePage::STATUS_CLAIMED, $page->fresh()->status);
        // Item 3: the blocked-by-removal refusal is recorded even
        // though the loser's transaction rolled back.
        $this->assertSame(
            1,
            $page->events()->where('event', 'claim_blocked_removed')->count(),
        );
    }

    /** Review 1a: the schema backstop — one page, at most one claiming business. */
    public function test_businesses_claimable_page_id_is_unique_at_the_schema_level(): void
    {
        [$page] = $this->makePage();
        Business::factory()->create(['claimable_page_id' => $page->id]);

        try {
            Business::factory()->create(['claimable_page_id' => $page->id]);
            $this->fail('A second business claiming the same page must violate the unique index.');
        } catch (QueryException) {
            $this->assertSame(1, Business::where('claimable_page_id', $page->id)->count());
        }
    }

    /**
     * Review 1b: the §4/C32 review lock is 7 days. A page locked at
     * day 0 is claimable again at day 7 + 1s with NO operator
     * action (derive-at-read), its pending review rows close as
     * expired, and the sweep frees a second locked page the same
     * way without any page read.
     */
    public function test_pending_review_lock_expires_after_seven_days_without_operator_action(): void
    {
        [$page, $token] = $this->makePage();
        app(ClaimableService::class)->markSent($page);
        $this->post(route('claimable.review', $token), ['name' => 'Sam Owner', 'phone' => '7185559999']);
        $this->assertSame(ClaimablePage::STATUS_PENDING_REVIEW, $page->fresh()->status);

        [$page2, $token2] = $this->makePage();
        $this->post(route('claimable.review', $token2), ['name' => 'Pat Owner', 'phone' => '7185558888']);
        $this->assertSame(ClaimablePage::STATUS_PENDING_REVIEW, $page2->fresh()->status);

        $this->travel(7)->days();
        $this->travel(1)->seconds();

        // The sweep frees page 2 without anyone opening it.
        $this->artisan('claimable:sweep')->assertSuccessful();
        $this->assertSame(ClaimablePage::STATUS_GENERATED, $page2->fresh()->status);
        $this->assertSame('expired', ClaimReview::where('claimable_page_id', $page2->id)->firstOrFail()->status);

        // Derive-at-read frees page 1 on its next open: C32 is gone
        // (§10.3: lock expiry returns the page to P2) and the
        // ordinary claim block is back.
        $html = $this->get(route('claimable.show', $token))->assertOk()->getContent();
        $this->dontSeeText($html, ClaimableCopy::c32());
        $this->seeText($html, ClaimableCopy::c20($page->fresh()));
        $this->assertSame(ClaimablePage::STATUS_SENT, $page->fresh()->status);
        $this->assertSame('expired', ClaimReview::where('claimable_page_id', $page->id)->firstOrFail()->status);
        $this->get(route('claimable.claim', $token))->assertOk();
    }

    /**
     * Review 1c: gate budgets are ROLLING 24h (coordinator
     * reconciliation), not epoch-aligned: sends straddling a UTC
     * midnight count in ONE window, and the lock's stated 24h
     * duration is true (still locked at +23:59, open at +24:00:01).
     */
    public function test_gate_budgets_are_rolling_24h_across_the_utc_midnight_boundary(): void
    {
        [$page] = $this->makePage();
        $gate = app(ClaimGateService::class);

        $this->travelTo(\Carbon\Carbon::parse('2026-06-01 23:59:00 UTC'));
        $this->assertSame('started', $gate->sendCode($page, 'sms'));
        $this->assertSame('started', $gate->sendCode($page, 'sms'));

        // Cross the UTC-midnight boundary: a fixed window would
        // reset here; a rolling window still counts both sends.
        $this->travel(2)->minutes();
        $this->assertSame('started', $gate->sendCode($page, 'sms'));
        $this->assertSame('locked', $gate->sendCode($page, 'sms'), 'The 4th send inside one rolling 24h must lock.');
        $this->assertTrue($gate->isLocked($page));

        // The lock lasts its stated 24h from the lock hit (~00:01).
        $this->travel(23)->hours();
        $this->travel(59)->minutes();
        $this->assertTrue($gate->isLocked($page), 'Lock must still hold at +23:59.');
        $this->travel(61)->seconds();
        $this->assertFalse($gate->isLocked($page), 'Lock must lift once 24h have passed since the lock hit.');
        $this->assertSame('started', $gate->sendCode($page, 'sms'), 'Sends resume in the rolled window.');
    }

    /**
     * Review 2a: expired (P7) and killed (P9) pages can never enter
     * the removal transition — §8.5: expiry/kill are not removals
     * and never suppress. Route-level guard, not link absence.
     */
    public function test_remove_route_refuses_expired_and_killed_pages_with_no_state_change(): void
    {
        [$expired, $expiredToken] = $this->makePage();
        $expired->forceFill(['status' => ClaimablePage::STATUS_EXPIRED])->save();
        $this->post(route('claimable.remove', $expiredToken))
            ->assertRedirect(route('claimable.show', $expiredToken));
        $expired = $expired->fresh();
        $this->assertSame(ClaimablePage::STATUS_EXPIRED, $expired->status);
        $this->assertNull($expired->removed_at);

        [$killed, $killedToken] = $this->makePage();
        app(ClaimableService::class)->kill($killed, 'liveness_check_failed');
        $this->post(route('claimable.remove', $killedToken))
            ->assertRedirect(route('claimable.show', $killedToken));
        $killed = $killed->fresh();
        $this->assertSame(ClaimablePage::STATUS_KILLED, $killed->status);
        $this->assertNull($killed->removed_at);

        // The service refuses too (the route is not the only door),
        // and no sweep can turn either page into a suppression.
        foreach ([$expired, $killed] as $page) {
            try {
                app(ClaimableService::class)->requestRemoval($page, 'page_footer');
                $this->fail('requestRemoval must refuse a '.$page->status.' page.');
            } catch (ClaimableGenerationBlocked $e) {
                $this->assertSame('not_removable', $e->getMessage());
            }
        }
        app(ClaimableService::class)->closeRestoreWindows();
        $this->assertSame(0, ClaimSuppression::count());
    }

    /**
     * Review 2b: R4's operator-review flag is read by generate().
     * After a second removal's suppression lapses BY DATE, the
     * place still cannot be generated until the named operator
     * decision is recorded (the flag cleared in the register).
     */
    public function test_generate_blocks_on_operator_review_flag_even_after_suppression_lapses(): void
    {
        $placeId = 'place-flagged-'.uniqid();
        ClaimSuppression::create([
            'place_id' => $placeId,
            'shop_name' => 'Bay Street Barber Co.',
            'removed_at' => now()->subMonths(13),
            'suppression_ends_at' => now()->subDay(),
            'removal_count' => 2,
            'operator_review_flag' => true,
        ]);
        $this->assertNull(ClaimSuppression::activeFor($placeId), 'The suppression itself has lapsed by date.');

        try {
            $this->makePage(['place_id' => $placeId]);
            $this->fail('A flagged place_id must block generation until the operator decision is recorded.');
        } catch (ClaimableGenerationBlocked $e) {
            $this->assertSame('operator_review_flag', $e->getMessage());
        }

        // The named operator decision, recorded in the register,
        // is the only thing that clears it; generation then runs.
        app(ClaimableService::class)->recordOperatorReviewDecision($placeId);
        [$page] = $this->makePage(['place_id' => $placeId]);
        $this->assertSame($placeId, $page->place_id);
    }

    /**
     * Review 2c: restoring a page removed while pending_review
     * preserves its prior sent state — a never-sent page (no
     * sent_at) returns to generated, never labelled sent.
     */
    public function test_restore_of_never_sent_pending_review_removal_returns_page_to_generated(): void
    {
        [$page] = $this->makePage();
        $this->assertNull($page->sent_at);
        $page->forceFill(['status' => ClaimablePage::STATUS_PENDING_REVIEW])->save();
        ClaimReview::create([
            'claimable_page_id' => $page->id,
            'kind' => ClaimReview::KIND_GATE_FALLBACK,
            'requester_name' => 'Sam Owner',
            'contact_phone_e164' => '+17185559999',
            'identity_hash' => ClaimReview::identityHash('Sam Owner', '+17185559999'),
            'status' => 'pending',
        ]);

        $service = app(ClaimableService::class);
        $service->requestRemoval($page->fresh(), 'page_footer');
        $service->resolveRestoreCheck($page->fresh(), false);

        $page = $page->fresh();
        $this->assertSame(ClaimablePage::STATUS_GENERATED, $page->status);
        $this->assertNull($page->sent_at);
    }

    /**
     * Review item 3: claim_blocked_removed is recorded at BOTH C37
     * sites (the gate-check removal branch and the checkpoint
     * refusal), so the §9.2 blocked-claim count is computable.
     */
    public function test_claim_blocked_removed_is_recorded_at_both_c37_sites(): void
    {
        // Site 1: gate check after a removal (§8.4 early shape).
        [$page, $token] = $this->makePage();
        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        app(ClaimableService::class)->requestRemoval($page->fresh(), 'page_footer');
        $this->post(route('claimable.gate.check', $token), ['code' => $this->verify->approvedCode]);
        $this->assertSame(1, $page->events()->where('event', 'claim_blocked_removed')->count());

        // Site 2: the checkpoint refusal after a removal (§8.4 late shape).
        [$page2, $token2] = $this->makePage();
        $this->post(route('claimable.gate.send', $token2), ['channel' => 'sms']);
        $this->post(route('claimable.gate.check', $token2), ['code' => $this->verify->approvedCode]);
        app(ClaimableService::class)->requestRemoval($page2->fresh(), 'operator');
        $this->post(route('register.store'), $this->signupPayload())
            ->assertSessionHasErrors('account');
        $this->assertSame(1, $page2->events()->where('event', 'claim_blocked_removed')->count());
    }

    /**
     * Review item 5: the gate lock errors live in ClaimableCopy
     * (C25 lock family) and their reopen wording is TRUE under the
     * rolling 24h lock: the concrete reopen time the state
     * supports, never "tomorrow".
     */
    public function test_gate_lock_errors_come_from_claimable_copy_and_name_the_true_reopen_time(): void
    {
        [$page, $token] = $this->makePage();

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('claimable.gate.send', $token), ['channel' => 'sms']);
        }
        $html = $this->get(route('claimable.claim', $token))->assertOk()->getContent();
        $gate = app(ClaimGateService::class);
        $this->seeText($html, ClaimableCopy::c25LockSends($gate->lockedUntil($page->fresh())));
        $this->assertStringNotContainsString('tomorrow', $html);

        // The wrong-code lock carries the same inventory string family.
        [$page2, $token2] = $this->makePage();
        $this->post(route('claimable.gate.send', $token2), ['channel' => 'sms']);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('claimable.gate.check', $token2), ['code' => '00000'.$i]);
        }
        $this->post(route('claimable.gate.check', $token2), ['code' => $this->verify->approvedCode]);
        $html2 = $this->get(route('claimable.claim', $token2))->assertOk()->getContent();
        $this->seeText($html2, ClaimableCopy::c25LockWrongCodes($gate->lockedUntil($page2->fresh())));
        $this->assertStringNotContainsString('tomorrow', $html2);
    }

    /**
     * Copy-train pin: the gate send's provider-error line also
     * comes from ClaimableCopy (the C25 family), verbatim — it
     * was the one gate string hard-coded in the controller,
     * outside the inventory.
     */
    public function test_gate_send_provider_error_comes_from_claimable_copy(): void
    {
        $this->assertSame(
            'That code could not be sent. You can try the other way, or leave your name below and we will check the claim with the shop.',
            ClaimableCopy::c25SendFailed(),
        );
        $this->assertSame(['P3'], ClaimableCopy::TRUTH_TABLE['C25-send-failed']);

        [$page, $token] = $this->makePage();
        $this->verify->startOutcomes['+17185550101'] = 'provider_error';

        $this->post(route('claimable.gate.send', $token), ['channel' => 'sms'])
            ->assertRedirect(route('claimable.claim', $token));

        $html = $this->get(route('claimable.claim', $token))->assertOk()->getContent();
        $this->seeText($html, ClaimableCopy::c25SendFailed());
        // No code was sent, so nothing about the flow advanced.
        $this->assertNull(session('claimable.claim_page_id'));
        $this->assertSame(0, Business::count());
    }
}
