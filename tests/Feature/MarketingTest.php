<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sprint 2 foundation and marketing evidence: design conformance
 * (FND-1 to FND-6, AP assertions from design-system.md Section 6.8)
 * and the Section 2 marketing pages with their Section 16
 * attribution behavior.
 */
class MarketingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One response cookie by name (the header bag's cookies are
     * objects; firstWhere cannot call their getters).
     */
    private function responseCookie(\Illuminate\Testing\TestResponse $response, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    public function test_home_renders_the_specced_sections_with_one_plan_card(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Turn Your Customers Into Your Best Marketers');
        $response->assertSee('How It Works');
        $response->assertSee('Why Use Easy Refer?');
        $response->assertSee('Powerful Features');
        $response->assertSee('Simple, Transparent Pricing');
        $response->assertSee('One plan: free to start, $2 per verified referral visit, never more than $99 a month.');
        $response->assertSee('Pay Per Visit');
        $response->assertSee('$0/month, $2 per verified referral visit');
        $response->assertSee('Ready to Grow Your Business?');
        $response->assertSee('Nothing charged until after your first 5 free visits');
        $response->assertSee('Get the free Referral Program Starter Kit');

        // AP-03: exactly one pricing card on the page.
        $this->assertSame(1, substr_count($response->getContent(), 'Pay Per Visit'));
    }

    public function test_home_renders_the_approved_proof_set_and_nothing_beyond_it(): void
    {
        $response = $this->get('/');

        // AP-07 as amended (OD-6 closed 2026-10-23): the approved
        // set renders, from the claims values and the plan table.
        $response->assertSee('400+');
        $response->assertSee('new customers per year on average');
        $response->assertSee('30%');
        $response->assertSee('2x');
        $response->assertSee('Running in real stores');
        $response->assertSee('Ungaro Coal Fired Pizza Cafe, with its referral code at the register.');
        $response->assertSee('Success Stories');
        $response->assertSee('We used to spend a lot of money on Google Ads. Now our customers do the marketing for us.');
        $response->assertSee('Nolan and Megan Ralston, Owners, Pizzeria');
        $response->assertSee('150+ New Customers In 3 Months');
        $response->assertSee('Talia Hollis, Hairstylist');
        $response->assertSee('110+ New Customers In 4 months');

        // And nothing outside the set: no invented or alternate
        // figures, no v1 alternate wordings, no About-only numbers.
        $response->assertDontSee('thousands of businesses');
        $response->assertDontSee('Join thousands');
        $response->assertDontSee('New Customers Annually');
        $response->assertDontSee('$1 Per Referral');
        $response->assertDontSee('80+');
        $response->assertDontSee('93%');
    }

    public function test_home_pricing_faq_row_carries_the_four_pricing_questions(): void
    {
        $response = $this->get('/');

        foreach (['What is Easy Refer?', 'How does the QR code referral system work?', 'How much does it cost?', 'Is there a setup fee?'] as $question) {
            $response->assertSee($question);
        }
        $response->assertDontSee('How do I track referrals?');
        $response->assertDontSee('Is my data secure?');
    }

    public function test_marketing_pages_carry_metadata_and_structured_data(): void
    {
        $home = $this->get('/');
        $home->assertOk();
        $home->assertSee('<link rel="canonical" href="'.url('/').'">', false);
        $home->assertSee('property="og:image" content="'.asset('images/photos/og-stores.jpg').'"', false);
        $home->assertSee('name="twitter:card" content="summary_large_image"', false);
        $home->assertSee('"@type": "Organization"', false);
        $home->assertSee('"@type": "WebSite"', false);
        $home->assertSee('name="description" content="Easy Refer turns your customers into referrers with QR codes, verified visits, and rewards you only pay for when they work."', false);

        $about = $this->get('/about');
        $about->assertSee('name="description" content="About Easy Refer: who we are, what we believe, and how our referral platform helps small businesses grow through word of mouth."', false);

        $vertical = $this->get('/for-cafes');
        $vertical->assertSee('name="description" content="Easy Refer for cafes: your regulars bring your next clients. Free to start, and you pay only when a brand-new customer walks in and redeems."', false);
    }

    public function test_sitemap_lists_the_public_marketing_routes_only(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');
        foreach (['/', '/about', '/faq', '/legal', '/refund', '/blog', '/for-barbers', '/for-salons', '/for-cafes', '/for-boutiques'] as $path) {
            $response->assertSee('<loc>'.url($path).'</loc>', false);
        }
        $response->assertDontSee('/auth');
        $response->assertDontSee('/refer');
    }

    public function test_robots_txt_points_at_the_sitemap(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertSee('User-agent: *');
        $response->assertSee('Sitemap: '.route('sitemap'));
    }

    public function test_claims_render_from_the_single_source_config(): void
    {
        config(['claims.new_customers_per_year' => '555+', 'claims.businesses' => '777+']);

        $this->get('/')->assertSee('555+')->assertDontSee('400+');
        $this->get('/about')->assertSee('777+');
    }

    public function test_pricing_card_start_free_button_is_the_primary(): void
    {
        $this->get('/')->assertSee('btn btn-primary mt-6 w-full', false);
    }

    public function test_home_pricing_renders_from_the_plan_table(): void
    {
        Plan::query()->where('key', 'pay_per_visit')->update(['per_referral_cents' => 300]);

        $response = $this->get('/');

        $response->assertSee('$3 per verified referral visit');
        $response->assertDontSee('$2 per verified referral visit');
    }

    public function test_calculator_is_present_with_its_inventory_labels(): void
    {
        $response = $this->get('/');

        $response->assertSee('How many people pay at your counter each week?');
        $response->assertSee('Your average ticket');
        $response->assertSee('Share of customers who bring a friend who visits');
        $response->assertSee('Estimates from your own numbers, not our results.');
    }

    public function test_vertical_pages_render_their_content_map_and_minimal_chrome(): void
    {
        foreach ([
            'barbers' => ['Built for barbershops: the client in your chair is your best advertising.', 'My chairs are already busy on Saturdays'],
            'salons' => ['Built for salons: every compliment on your work is a referral waiting to happen.', 'My clients already rebook'],
            'cafes' => ['Built for cafes: your morning regulars know everyone on the block.', 'Margins are too thin for discounts'],
            'boutiques' => ['Built for boutiques: every "where did you get that?" is a referral waiting to happen.', 'I am not a discount brand'],
        ] as $slug => [$line, $objection]) {
            $response = $this->get('/for-'.$slug);

            $response->assertOk();
            $response->assertSee('Your regulars bring your next clients');
            $response->assertSee($line);
            $response->assertSee($objection);
            $response->assertSee('Free to start. You pay $2 only when a brand-new customer walks in and redeems. Never more than $99 a month.');
            $response->assertSee('Your first 5 verified visits are free.');
            $response->assertSee('Get the free Referral Program Starter Kit');
            // Minimal chrome: the header carries no Site nav on a
            // paid landing (the shared footer still links About).
            $response->assertDontSee('aria-label="Site"', false);
            // AP-07 on verticals too.
            $response->assertDontSee('400+');
        }
    }

    public function test_unknown_vertical_is_a_404(): void
    {
        $this->get('/for-dentists')->assertNotFound();
    }

    public function test_about_renders_the_approved_claims(): void
    {
        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('Empowering Growth Through Referrals');
        $response->assertSee('Our Mission');
        $response->assertSee('Our Vision');
        $response->assertSee('Core Values');
        $response->assertSee('info@easy-refer.com');
        $response->assertSee('+1 (833) 803-2400');
        $response->assertSee('104 West 27th Street, New York, NY 10001');

        // The OD-6 approved set (closed 2026-10-23): the founding
        // clause and the Our Impact counters, from the claims
        // values. The "Trusted by" chip form never renders.
        $response->assertSee('Founded in 2023');
        $response->assertSee('Our Impact');
        $response->assertSee('400+');
        $response->assertSee('Businesses Empowered');
        $response->assertSee('80+');
        $response->assertSee('Successful Referrals');
        $response->assertSee('93%');
        $response->assertSee('Customer Satisfaction');
        $response->assertDontSee('Trusted by');
    }

    public function test_faq_renders_all_six_questions_with_plan_amounts(): void
    {
        $response = $this->get('/faq');

        $response->assertOk();
        foreach (['What is Easy Refer?', 'How does the QR code referral system work?', 'How much does it cost?', 'How do I track referrals?', 'Is there a setup fee?', 'Is my data secure?'] as $question) {
            $response->assertSee($question);
        }
        $response->assertSee('You pay $2 only when a referred friend actually visits your store and redeems. There is no monthly fee, your first 5 verified visits are free, and you never pay more than $99 in a month.', false);
    }

    public function test_legal_page_keeps_its_anchors_and_names_only_the_real_processors(): void
    {
        $response = $this->get('/legal');

        $response->assertOk();
        $response->assertSee('id="privacy-policy"', false);
        $response->assertSee('id="terms-of-service"', false);
        $response->assertSee('id="compliance"', false);
        $response->assertSee('er_attrib');
        $response->assertSee('90 days');
        $response->assertSee('Square');
        $response->assertSee('Twilio');
        $response->assertDontSee('Stripe');
        $response->assertDontSee('Paddle');
        $response->assertDontSee('Lemon Squeezy');
        // OD-10 default: no optional customer email is collected, so
        // the policy does not claim one (spec Section 2.4).
        $response->assertDontSee('email (optional)');
    }

    public function test_refund_page_renders_the_policy_with_the_plan_amount(): void
    {
        $response = $this->get('/refund');

        $response->assertOk();
        $response->assertSee('Businesses are charged $2.00 per verified referral visit.');
        $response->assertSee('We acknowledge disputes within 2 business days');
        $response->assertDontSee('Stripe');
    }

    public function test_blog_index_shows_the_intro_and_the_empty_state(): void
    {
        $response = $this->get('/blog');

        $response->assertOk();
        $response->assertSee('Welcome to the Easy Refer blog.');
        $response->assertSee('Posts are on the way. Check back soon.');
    }

    public function test_newsletter_signup_creates_a_lead_and_queues_the_confirmation(): void
    {
        Mail::fake();

        $response = $this->post('/newsletter', ['email' => 'owner@example.com']);

        $response->assertRedirect();
        $response->assertSessionHas('newsletter_status', 'Thanks. Please check your email to confirm your subscription.');
        $this->assertDatabaseHas('leads', ['kind' => 'newsletter', 'email' => 'owner@example.com']);
        Mail::assertQueued(\App\Mail\NewsletterConfirmationMail::class);
    }

    public function test_newsletter_confirmation_marks_the_lead_confirmed(): void
    {
        $token = \App\Support\OpaqueToken::generate();
        $lead = Lead::create([
            'kind' => 'newsletter',
            'email' => 'owner@example.com',
            'token_hash' => \App\Support\OpaqueToken::hash($token),
        ]);

        $this->get('/newsletter/confirm/'.$token)
            ->assertOk()
            ->assertSee('You are on the list');

        $this->assertNotNull($lead->fresh()->confirmed_at);
    }

    public function test_starter_kit_form_captures_a_lead(): void
    {
        $response = $this->post('/starter-kit', ['email' => 'owner@example.com', 'source' => 'home']);

        $response->assertRedirect();
        $response->assertSessionHas('lead_status', 'You are on the list');
        $this->assertDatabaseHas('leads', ['kind' => 'starter_kit', 'email' => 'owner@example.com', 'source' => 'home']);
    }

    public function test_there_is_no_standalone_starter_kit_route(): void
    {
        // The only starter-kit surface is the POST capture; no page
        // serves the kit by itself (spec Section 2.7).
        $this->get('/starter-kit')->assertStatus(405);
    }

    public function test_attribution_cookie_is_set_on_a_tagged_landing_and_e1_recorded(): void
    {
        $response = $this->get('/faq?utm_source=google&utm_campaign=spring&gclid=abc123');

        $response->assertOk();
        $cookie = $this->responseCookie($response, 'er_attrib');
        $this->assertNotNull($cookie, 'er_attrib cookie is set');
        // The Set-Cookie value is the framework-encrypted payload;
        // its contents are asserted end to end by the signup test
        // below (the acq_ write reads the decrypted cookie).
        $this->assertNotSame('', (string) $cookie->getValue());
        $this->assertDatabaseHas('attribution_events', ['event_key' => 'E1', 'event_name' => 'landing_view']);
    }

    public function test_signup_writes_the_first_touch_onto_the_business_and_fires_e3(): void
    {
        $response = $this->get('/faq?utm_source=google&utm_medium=cpc&utm_campaign=spring&gclid=abc123');
        $this->carryCookiesFrom($response);

        $this->post(route('register'), [
            'business_name' => 'Acme Cuts',
            'business_type' => 'barber',
            'name' => 'Owner One',
            'email' => 'new.owner@example.test',
            'phone' => '(212) 555-0142',
            'password' => 'a long phrase for testing',
            'password_confirmation' => 'a long phrase for testing',
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ])->assertRedirect(route('dashboard'));

        $business = \App\Models\Business::query()->where('name', 'Acme Cuts')->firstOrFail();
        $this->assertSame('google', $business->acq_source);
        $this->assertSame('cpc', $business->acq_medium);
        $this->assertSame('spring', $business->acq_campaign);
        $this->assertSame('abc123', $business->acq_gclid);
        $this->assertSame('/faq', $business->acq_landing_path);
        $this->assertNotNull($business->acq_captured_at);
        $this->assertNotNull($business->current_plan_id);
        $this->assertDatabaseHas('attribution_events', ['event_key' => 'E3', 'business_id' => $business->id]);
    }

    public function test_attribution_first_touch_wins(): void
    {
        $payload = json_encode(['source' => 'facebook', 'medium' => null, 'campaign' => null, 'term' => null, 'content' => null, 'gclid' => null, 'fbclid' => 'x', 'ttclid' => null, 'landing_path' => '/', 'referrer_host' => null]);

        $response = $this->withCookie('er_attrib', $payload)->get('/faq?utm_source=google');

        $cookie = $this->responseCookie($response, 'er_attrib');
        $this->assertNull($cookie, 'an existing first touch is never replaced');
        $this->assertDatabaseMissing('attribution_events', ['event_key' => 'E1']);
    }

    public function test_declining_consent_deletes_the_attribution_cookie_and_stops_capture(): void
    {
        $response = $this->post('/consent', ['choice' => 'declined']);

        $names = collect($response->headers->getCookies())->map(fn ($c) => $c->getName());
        $this->assertContains('er_consent', $names);
        $attrib = $this->responseCookie($response, 'er_attrib');
        $this->assertNotNull($attrib);
        $this->assertTrue($attrib->getExpiresTime() < time(), 'the er_attrib cookie is expired on decline');

        // And with the decline on record, a tagged landing captures nothing.
        $this->withCookie('er_consent', 'declined')
            ->get('/faq?utm_source=google')
            ->assertOk();
        $this->assertDatabaseMissing('attribution_events', ['event_key' => 'E1']);
    }

    public function test_no_analytics_markup_renders_without_configured_ids(): void
    {
        $response = $this->get('/');

        $response->assertDontSee('googletagmanager');
        $response->assertDontSee('fbevents');
        $response->assertDontSee('gtag(');
    }

    public function test_consent_banner_is_present_on_marketing_and_absent_from_the_flow(): void
    {
        // AP-16.
        $this->get('/')->assertSee('We use cookies to measure how visitors find us.');
        $this->get(route('login'))->assertDontSee('We use cookies to measure how visitors find us.');
    }

    public function test_ap01_ap02_no_gradients_or_glassmorphism_in_rendered_pages(): void
    {
        foreach (['/', '/for-barbers', '/about', '/faq'] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertStringNotContainsString('linear-gradient', $html);
            $this->assertStringNotContainsString('radial-gradient', $html);
            $this->assertStringNotContainsString('backdrop-filter', $html);
            $this->assertStringNotContainsString('backdrop-blur', $html);
        }
    }

    public function test_ap13_off_palette_hexes_do_not_render(): void
    {
        foreach (['/', '/for-cafes', route('login'), route('register')] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertStringNotContainsString('#2B6CB0', $html);
            $this->assertStringNotContainsString('#A0AEC0', $html);
        }
    }

    public function test_foundation_landmarks_and_skip_link_on_marketing_and_dashboard_shells(): void
    {
        // FND-3 and Section 6.6 landmarks.
        $home = $this->get('/');
        $home->assertSee('Skip to content');
        $home->assertSee('aria-label="Site"', false);
        $home->assertSee('aria-label="Footer"', false);

        $this->seed(\Database\Seeders\TwoBusinessFixtureSeeder::class);
        $owner = \App\Models\User::query()->where('email', \Database\Seeders\TwoBusinessFixtureSeeder::A_EMAIL)->firstOrFail();

        $dashboard = $this->actingAs($owner)->get(route('dashboard'));
        $dashboard->assertSee('Skip to content');
        $dashboard->assertSee('aria-label="Primary"', false);
    }

    public function test_notices_region_renders_with_a_form_error(): void
    {
        $this->seed(\Database\Seeders\TwoBusinessFixtureSeeder::class);

        // The Notices region renders when a page carries a status
        // or an error summary (here, a failed sign-in on /auth).
        $failedLogin = $this->followingRedirects()->from(route('login'))->post(route('login'), [
            'identifier' => \Database\Seeders\TwoBusinessFixtureSeeder::A_EMAIL,
            'password' => 'not-the-password',
        ]);
        $failedLogin->assertSee('aria-label="Notices"', false);
    }

    public function test_signup_records_e2_once_per_session_and_e3_at_signup(): void
    {
        $this->get(route('register'));
        $this->get(route('register'));
        $this->assertDatabaseCount('attribution_events', 1);
        $this->assertDatabaseHas('attribution_events', ['event_key' => 'E2']);
    }
}
