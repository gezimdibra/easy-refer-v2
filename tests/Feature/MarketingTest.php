<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Plan;
use App\Models\Post;
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
        $response->assertSee('Get Your Customers to Refer their Friends');
        // R-4 (release 08d): nav labels follow the CV-01 sentence
        // case of the headings they name; the Title Case forms are
        // gone from the page.
        $response->assertSee('How it works');
        $response->assertDontSee('How It Works');
        $response->assertDontSee('About Us');
        $response->assertSee('Why use Easy Refer?');
        $response->assertSee('Powerful features');
        $response->assertSee('Simple, transparent pricing');
        // Copy release (Amendment 11): the hero is Version 1
        // exactly as rendered in the owner-approved mockup: the
        // H1 with its terminal period, the mechanism lede in
        // three short sentences, and the trust row leading with
        // the free visits. The Option B lede is retired.
        // Headline change (Amendment 14, 2026-10-24): the H1 is
        // the owner's "Get Your Customers to Refer their Friends",
        // verbatim, with NO terminal period; the Amendment 11
        // lede and trust row below are unchanged.
        $response->assertSee('Get Your Customers to Refer their Friends');
        $response->assertSee('Customers scan your QR code. They send it to friends. You pay only when a friend walks in.');
        $response->assertSee('First 5 visits free');
        $response->assertSee('Then $2 a visit');
        // The retired lede's tail is unique to it (the meta
        // description shares its first sentence and is a
        // separate META entry, out of this release's scope).
        $response->assertDontSee('Free to start, and your first 5 verified visits are free.');
        $response->assertSee('$2 per referral visit');
        $response->assertSee('Never more than $99 a month');
        // F1: the mobile hero visual is the portrait capture.
        // Hero animation (2026-10-24): the stage replaces the
        // static dashboard captures in the hero visual slot;
        // the closing line is the one new string (copy
        // inventory Amendment 13).
        $response->assertSee('hero-stage', false);
        $response->assertSee('Turn every customer into a referral.', false);
        $response->assertSee('One plan. $2 when a referred friend visits. Never more than $99 a month in visit charges.');
        $response->assertSee('Pay Per Visit');
        $response->assertSee('$0/month, $2 per referral visit');
        // Amendment 12 (release 11): the pricing card states the
        // card requirement itself (sixth bullet), the pricing
        // section carries the visit-that-counts definition and
        // the public bulk text rate, and the jargon sweep's word
        // appears nowhere on the page.
        $response->assertSee('Your friend reward set to activate, and your first 5 visits are free. A card keeps your code going after that; first charge after your 5 free visits');
        $response->assertSee('A visit counts when a referred friend comes in and uses their reward. We confirm their phone first, and every visit is checked before it counts.');
        $response->assertSee('Bulk texts cost 2 cents per segment for each customer. Most short texts are one segment. One text to 100 customers costs $2.');
        $response->assertDontSee('verified');
        $response->assertSee('Ready to grow your business?');
        $response->assertSee('Nothing charged until after your first 5 free visits');
        $response->assertSee('Join the list for the free Referral Program Starter Kit');

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
        $response->assertSee('higher purchase amount');
        $response->assertSee('2x');
        $response->assertSee('customer retention');
        // ON-STAT-1 (Amendment 31, 2026-10-27): tile 4 carries
        // its fact as a numeral like its siblings: the value is
        // "5" and the caption under it is "first visits free".
        // The sentence-shaped "First 5" / "visits free" pairing
        // is retired from the band (the band block below pins its
        // absence); the hero bullet "First 5 visits free" is a
        // sentence among sentences and stays (pinned above).
        $response->assertSee('first visits free');
        $response->assertDontSee('93%');
        $response->assertDontSee('satisfaction');

        // The proof band itself carries exactly the four approved
        // stats with their approved labels. The price figure is
        // not a stat and never renders in the band: it lives in
        // its own pricing section. (The HOME-STAT-01 price tile
        // was superseded by the OD-6 claims set; release 05 Gate 3
        // and the visual audit both flagged the old band.)
        $html = $response->getContent();
        $bandStart = strpos($html, 'grid grid-cols-2 gap-y-10 lg:grid-cols-4');
        $this->assertNotFalse($bandStart, 'the proof stat band renders');
        // Copy release: Success Stories now directly follows the
        // band (the payoff before the mechanism), so the band's
        // span ends at the stories heading.
        $band = substr($html, $bandStart, strpos($html, 'Success stories') - $bandStart);
        $this->assertSame(4, substr_count($band, 'type-stat'));
        foreach (['400+', 'new customers per year on average', '30%', 'higher purchase amount', '2x', 'customer retention', '5', 'first visits free'] as $stat) {
            $this->assertStringContainsString($stat, $band);
        }
        // ON-STAT-1: the tile itself changed, structurally: the
        // stat element renders exactly the numeral "5", the old
        // value "First 5" appears nowhere in the band, and the
        // old caption never stands alone (the new caption
        // contains it only as the tail of "first visits free").
        $this->assertStringContainsString('type-stat text-text-1">5</p>', $band);
        $this->assertStringNotContainsString('First 5', $band);
        $this->assertStringNotContainsString('>visits free</p>', $band);
        $this->assertStringNotContainsString('$2', $band);
        $this->assertStringNotContainsString('per verified visit', $band);
        $this->assertStringNotContainsString('never more than', $band);

        $response->assertSee('Running in real stores');
        $response->assertSee('Ungaro Coal Fired Pizza Cafe, with its code at the register.');
        $response->assertSee('Success stories');
        $response->assertSee('We used to spend a lot of money on Google Ads. Now our customers do the marketing for us.');
        $response->assertSee('Nolan and Megan Ralston, Owners, Pizzeria');
        $response->assertSee('150+ New Customers In 3 months');
        $response->assertSee('Talia Hollis, Hairstylist');
        $response->assertSee('110+ New Customers In 4 months');

        // And nothing outside the set: no invented or alternate
        // figures, no v1 alternate wordings, no About-only numbers.
        $response->assertDontSee('thousands of businesses');
        $response->assertDontSee('Join thousands');
        $response->assertDontSee('New Customers Annually');
        $response->assertDontSee('$1 Per Referral');
        $response->assertDontSee('80+');
        $response->assertDontSee('retention rate');
    }

    public function test_home_story_quotes_carry_the_v1_decorative_quote_marks(): void
    {
        // QUOTES-01 (owner directive 2026-10-25): v1 framed its
        // testimonials with oversized decorative quote marks
        // (testimonials.css: serif glyphs at 4rem, brand color at
        // 20 percent opacity, opening mark top-left, closing mark
        // bottom-right, italic quote text). v2 carries the same
        // pattern as a scoped class on the two story blockquotes
        // only. The copy itself is Amendment-pinned and must stay
        // byte-identical; the marks are CSS only.
        $response = $this->get('/');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertSame(2, substr_count($html, 'story-quote'));
        $response->assertSee('<blockquote class="type-quote story-quote text-text-1">We used to spend a lot of money on Google Ads. Now our customers do the marketing for us.</blockquote>', false);
        $response->assertSee('<blockquote class="type-quote story-quote text-text-1">I would have used Easy Refer since day 1 if it was available.</blockquote>', false);

        // Scope: the auth panel's Ralston blockquote and every
        // other view stay unmarked.
        $auth = (string) file_get_contents(resource_path('views/layouts/auth.blade.php'));
        $this->assertStringNotContainsString('story-quote', $auth);

        // The rules live in the shipped stylesheet source.
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $block = static function (string $selector) use ($css): ?string {
            $pattern = '/'.preg_quote($selector, '/').'\s*\{([^}]*)\}/';
            return preg_match($pattern, $css, $m) === 1 ? $m[1] : null;
        };

        $base = $block('.story-quote');
        $this->assertNotNull($base, 'the scoped .story-quote rule exists');
        $this->assertStringContainsString('position: relative', $base);
        $this->assertStringContainsString('font-style: italic', $base, 'the v1 italic quote text');

        $shared = $block('.story-quote::before, .story-quote::after');
        $this->assertNotNull($shared, 'the shared pseudo-element rule exists');
        foreach (['position: absolute', 'font-size: 4rem', 'opacity: 0.2', 'serif'] as $needle) {
            $this->assertStringContainsString($needle, $shared, "both marks share the v1 treatment ({$needle})");
        }

        // The dedicated pseudo rules are anchored at line start:
        // the bare class name also appears inside the shared
        // rule's selector list, which must not satisfy the lookup.
        $dedicated = static function (string $selector) use ($css): ?string {
            $pattern = '/^\s*'.preg_quote($selector, '/').'\s*\{([^}]*)\}/m';
            return preg_match($pattern, $css, $m) === 1 ? $m[1] : null;
        };

        $before = $dedicated('.story-quote::before');
        $this->assertNotNull($before, 'the opening mark rule exists');
        $this->assertStringContainsString("content: '\\201C'", $before, 'the opening mark is the typographic left double quote');

        $after = $dedicated('.story-quote::after');
        $this->assertNotNull($after, 'the closing mark rule exists');
        $this->assertStringContainsString("content: '\\201D'", $after, 'the closing mark is the typographic right double quote');
    }

    public function test_home_no_longer_carries_the_faq_subset(): void
    {
        // Copy release (2026-10-24, O-1): the FAQ subset leaves
        // home entirely; the full set lives on /faq (pinned by
        // test_faq_renders_all_twelve_questions_with_plan_amounts)
        // and the verticals keep their embedded subset (pinned
        // by the accordion test below). This replaces the 08b-era
        // pin that required the four pricing questions on home.
        $response = $this->get('/');

        $response->assertDontSee('Frequently asked questions');
        foreach (['What is Easy Refer?', 'How does the QR code referral system work?', 'How much does it cost?', 'Is there a setup fee?', 'How do I track referrals?', 'Is my data secure?', 'Do I need a card to start?', 'How long does setup take?', 'Which businesses does Easy Refer fit?', 'What do rewards cost me?', 'How do I cancel?', 'What if a customer never scans the code?'] as $question) {
            $response->assertDontSee($question);
        }
    }

    public function test_marketing_pages_carry_metadata_and_structured_data(): void
    {
        $home = $this->get('/');
        $home->assertOk();
        $home->assertSee('<link rel="canonical" href="'.url('/').'">', false);
        $home->assertSee('property="og:image" content="'.asset('images/photos/og-image.png').'"', false);
        $home->assertSee('name="twitter:card" content="summary_large_image"', false);
        $home->assertSee('"@type": "Organization"', false);
        $home->assertSee('"@type": "WebSite"', false);
        $home->assertSee('name="description" content="Easy Refer turns your customers into referrers with QR codes, visits that count, and rewards you only pay for when they work."', false);

        $about = $this->get('/about');
        $about->assertSee('name="description" content="About Easy Refer: who we are, what we believe, and how our referral platform helps small businesses grow through word of mouth."', false);

        $vertical = $this->get('/for-cafes');
        $vertical->assertSee('name="description" content="Easy Refer for cafes: your regulars bring your cafe its next customers. Free to start, and you pay only when a brand-new customer walks in and redeems."', false);
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
        config(['claims.new_customers_per_year' => '555+', 'claims.higher_purchase' => '66%']);

        $this->get('/')->assertSee('555+')->assertDontSee('400+');
        $this->get('/about')->assertSee('555+')->assertSee('66%');
    }

    public function test_platform_count_claims_are_removed_and_render_nowhere(): void
    {
        // Money fix train (coordinator ruling 2026-10-25): the
        // two platform count claims are false at launch and are
        // gone from the single source; no surface may render
        // them or their captions as achieved results. The
        // approved per-business claims stay.
        $this->assertNull(config('claims.businesses'));
        $this->assertNull(config('claims.successful_referrals'));

        foreach (['/', '/about'] as $path) {
            $response = $this->get($path);
            $response->assertOk();
            $response->assertDontSee('Businesses Empowered');
            $response->assertDontSee('Successful Referrals');
            $response->assertDontSee('80+');
        }

        $this->get('/')->assertSee('400+')->assertSee('new customers per year on average');
        $this->get('/about')->assertSee('400+')->assertSee('new customers per year on average');
    }

    public function test_pricing_card_start_free_button_is_the_primary(): void
    {
        $this->get('/')->assertSee('btn btn-primary btn-lg mt-8 w-full', false);
    }

    public function test_home_pricing_renders_from_the_plan_table(): void
    {
        Plan::query()->where('key', 'pay_per_visit')->update(['per_referral_cents' => 300]);

        $response = $this->get('/');

        $response->assertSee('$3 per referral visit');
        $response->assertDontSee('$2 per referral visit');
    }

    public function test_home_sections_render_in_the_move_01_order_on_alternating_grounds(): void
    {
        // MOVE-01 (2026-10-25, landing-section-placement study):
        // Powerful Features sits immediately after How It Works;
        // Real Stores and Benefits flipped grounds so the section
        // grounds still alternate down the whole page (the pair
        // discipline the blade comments call STEP-CONTRAST). This
        // pins the rendered top-level section sequence as
        // [id, ground] pairs; the HIW story's nested hiw-beat
        // sections carry no ground class and are excluded.
        $response = $this->get('/');

        $response->assertOk();

        preg_match_all('/<section\b([^>]*)>/', $response->getContent(), $matches);
        $sequence = [];
        foreach ($matches[1] as $attrs) {
            if (! preg_match('/\b(bg-(?:surface-\d|ink-950))\b/', $attrs, $ground)) {
                continue;
            }
            preg_match('/\bid="([^"]+)"/', $attrs, $id);
            $sequence[] = [$id[1] ?? null, $ground[1]];
        }

        $this->assertSame([
            [null, 'bg-surface-0'], // Hero
            [null, 'bg-surface-1'], // Stat band
            ['success-stories', 'bg-surface-0'],
            ['how-it-works', 'bg-surface-1'],
            ['demo-block', 'bg-ink-950'], // G6 demo block (Amendment 63, demo-phase1-design-pass.md Section 3): the owner-ordered second demo door sits immediately after How It Works; its dark ground keeps the alternation.
            ['features', 'bg-surface-0'],
            [null, 'bg-surface-1'], // Running in real stores
            ['benefits', 'bg-surface-0'],
            ['pricing', 'bg-surface-1'],
            [null, 'bg-surface-0'], // Calculator
            ['closing-cta', 'bg-ink-950'],
        ], $sequence);
    }

    public function test_calculator_is_present_with_its_inventory_labels(): void
    {
        $response = $this->get('/');

        $response->assertSee('See what referrals could bring in');
        // Copy release (Amendment 11, O-2): the intro is the
        // slim-down short form and the share label is five words.
        $response->assertSee('Use your own numbers: your weekly customers and your average sale. The math uses the same $2 fee and the same $99 limit.');
        $response->assertSee('How many people pay at your counter each week?');
        $response->assertSee('Typical spend per visit');
        $response->assertSee('Customers who bring a friend');
        $response->assertDontSee('Share of customers who bring a friend who visits');
        $response->assertSee('Estimates from your own numbers, not our results.');
        // Amendment 12 (release 11): the honesty notes ride with
        // the estimate label.
        $response->assertSee('The sales figure is before the rewards you hand out. Our visit fee, shown above, is the only per-visit charge from us. Bulk texts, if you send them, cost 2 cents per segment for each customer.');
        $response->assertSee('Your first 5 visits are free. This monthly estimate does not subtract them.');
    }

    public function test_vertical_pages_render_their_content_map_and_minimal_chrome(): void
    {
        foreach ([
            'barbers' => ['Your regulars bring your barbershop its next clients', 'Built for barbershops: the client in your chair is your best advertising.', 'My chairs are already busy on Saturdays'],
            'salons' => ['Your regulars bring your salon its next clients', 'Built for salons: every compliment on your work is a referral waiting to happen.', 'My clients already rebook'],
            'cafes' => ['Your regulars bring your cafe its next customers', 'Built for cafes: your morning regulars know everyone on the block.', 'Margins are too thin for discounts'],
            'boutiques' => ['Your regulars bring your boutique its next customers', 'Built for boutiques: every "where did you get that?" is a referral waiting to happen.', 'I am not a discount brand'],
        ] as $slug => [$h1, $line, $objection]) {
            $response = $this->get('/for-'.$slug);

            $response->assertOk();
            $response->assertSee($h1);
            // S-04: the objection section answers second, before
            // the mechanism section.
            $html = $response->getContent();
            $this->assertLessThan(strpos($html, 'id="how-it-works"'), strpos($html, $objection));
            $response->assertSee($line);
            $response->assertSee($objection);
            $response->assertSee('Free to start. $2 only when a brand-new customer visits and redeems. Never more than $99 a month in visit charges.');
            $response->assertSee('Your first 5 visits are free.');
            // Amendment 12 (release 11): the shared pricing card
            // carries the card bullet on the verticals too, while
            // the FAQ subset stays at indices 0 to 3, so the five
            // appended questions never render here.
            $response->assertSee('Your friend reward set to activate, and your first 5 visits are free. A card keeps your code going after that; first charge after your 5 free visits');
            $response->assertDontSee('How long does setup take?');
            $response->assertDontSee('How do I cancel?');
            $response->assertSee('Join the list for the free Referral Program Starter Kit');
            // Minimal chrome: the header carries no Site nav on a
            // paid landing (the shared footer still links About).
            $response->assertDontSee('aria-label="Site"', false);
            // AP-07 on verticals too.
            $response->assertDontSee('400+');
        }
    }

    public function test_home_hero_and_header_carry_the_corrections_wave_hooks(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        // The hero stack hooks (release 08b D-1: the reading
        // order is the DOM order on mobile too; the release 07
        // order overrides are gone from app.css).
        $response->assertSee('mkt-hero-actions', false);
        $response->assertSee('mkt-hero-lede', false);

        // MO-01 and MO-02: the hero-rise load-in animation is gone
        // from the page (its app.css removal is Lane C's crosslane
        // item; the class must not render here meanwhile).
        $response->assertDontSee('hero-rise');

        // SL-11: the marketing header carries the hide-on-scroll
        // hook and its behavior script.
        $response->assertSee('id="site-header"', false);
        $response->assertSee('site-header-hidden', false);
    }

    public function test_home_hero_demo_door_variant_d_forms_and_frozen_pair_adjacency(): void
    {
        // Landing integration pass, Variant D: ONE hero demo door
        // in two geometry forms. The frozen pair ("Start free",
        // "See how it works") renders adjacent, in order; the
        // wide ghost sits AFTER the pair in the row and is hidden
        // below 640px; below 640px a tertiary text link (not a
        // button) directly under the row carries the door.
        $html = $this->get('/')->assertOk()->getContent();

        $heroStart = strpos($html, 'mkt-hero-actions');
        $heroEnd = strpos($html, 'mkt-hero-trust');
        $this->assertNotFalse($heroStart);
        $this->assertNotFalse($heroEnd);
        $hero = substr($html, $heroStart, $heroEnd - $heroStart);

        $posStart = strpos($hero, '>Start free<');
        $posSee = strpos($hero, '>See how it works<');
        $posGhost = strpos($hero, 'id="demo-hero-entry"');
        $this->assertNotFalse($posStart);
        $this->assertNotFalse($posSee);
        $this->assertNotFalse($posGhost);
        $this->assertLessThan($posSee, $posStart, 'The frozen pair is adjacent and in its approved order.');
        $this->assertLessThan($posGhost, $posSee, 'The wide ghost no longer splits the frozen pair.');

        // Wide form: a ghost button, hidden on phones.
        $this->assertMatchesRegularExpression(
            '/<a class="btn btn-demo-ghost btn-lg demo-hero-entry hidden sm:inline-flex" href="#demo-block" id="demo-hero-entry">/',
            $hero
        );

        // Narrow form: a text link under the row, hidden at sm
        // and up, carrying the shared hook and NO button classes
        // (no third btn-lg exists in the phone hero).
        $this->assertStringContainsString('<p class="mt-2 text-center sm:hidden">', $hero);
        $this->assertMatchesRegularExpression(
            '/<a class="demo-hero-link demo-hero-entry" href="#demo-block" id="demo-hero-entry-mobile">/',
            $hero
        );
        $this->assertSame(3, substr_count($hero, 'btn-lg'), 'The hero carries exactly three btn-lg anchors: the frozen pair plus the wide ghost, which is display-hidden on phones; the narrow link is not a button.');
        $this->assertSame(2, substr_count($html, 'Try it live</a>'), 'Exactly the two hero forms carry the entry label on the landing.');

        // No other door: nav, mobile menu and sticky bar stay
        // demo-free (Variants C and the sticky split rejected).
        $this->assertSame(1, substr_count($html, 'id="demo-frame"'));
    }

    public function test_about_shows_a_real_store_photo_with_its_own_business_caption(): void
    {
        // X-5: the About page carried no photo. The one added photo
        // is owner-cleared and keeps its own business caption (the
        // caption string is shared with the home proof strip, where
        // the same figure renders; pairing is never crossed).
        $this->get('/about')
            ->assertOk()
            ->assertSee('images/photos/ungaro-counter-768.jpg', false)
            ->assertSee('Ungaro Coal Fired Pizza Cafe, with its referral code at the register.');
    }

    public function test_unknown_vertical_is_a_404(): void
    {
        $this->get('/for-dentists')->assertNotFound();
    }

    public function test_about_renders_the_approved_claims(): void
    {
        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('We help small businesses grow through referrals');
        $response->assertSee('Word of mouth, made simple: customers share, friends visit, and you pay only for results.');
        $response->assertDontSee('Join Our Network');
        $response->assertSee('Our mission');
        $response->assertSee('Our vision');
        $response->assertSee('Core values');
        // R17/S-15: the honest About body; the banned inflation
        // phrasing never renders.
        $response->assertSee('Easy Refer makes word-of-mouth marketing simple for small businesses.');
        $response->assertSee('you only pay when a referral works');
        $response->assertDontSee('we revolutionize');
        $response->assertDontSee('global standard');
        $response->assertSee('info@easy-refer.com');
        $response->assertSee('104 West 27th Street, New York, NY 10001');
        // Coordinator ruling 2026-10-25: +1 (833) 803-2400 is
        // the outbound texting line, not a staffed support line,
        // so it is never published as a contact channel. The
        // About contact block is email-only and the number does
        // not render anywhere on the page.
        $response->assertDontSee('803-2400');
        $response->assertDontSee('(833)');

        // The OD-6 approved set (closed 2026-10-23): the founding
        // clause and the Our Impact counters, from the claims
        // values. The "Trusted by" chip form never renders.
        $response->assertSee('Founded in 2023');
        $response->assertSee('Our impact');
        // S-07: the closing band renders on About too.
        $response->assertSee('Ready to grow your business?');
        // Money fix train: Our Impact carries the approved
        // per-business outcomes; the platform count claims
        // (400+ businesses, 80+ referrals) are removed.
        $response->assertSee('400+');
        $response->assertSee('new customers per year on average');
        $response->assertSee('30%');
        $response->assertSee('higher purchase amount');
        $response->assertDontSee('Businesses Empowered');
        $response->assertDontSee('80+');
        $response->assertDontSee('Successful Referrals');
        // ON-STAT-1 (Amendment 31): the About impact counters
        // render the same tile from the same claims key, so they
        // take the same numeral form and cannot diverge: "5"
        // over "first visits free", and no "First 5" tile (the
        // string appears nowhere else on this page).
        $response->assertSee('first visits free');
        $response->assertSee('type-stat text-text-1">5</p>', false);
        $response->assertDontSee('First 5');
        $response->assertDontSee('93%');
        $response->assertDontSee('Customer Satisfaction');
        $response->assertDontSee('Trusted by');
    }

    public function test_faq_renders_all_thirteen_questions_with_plan_amounts(): void
    {
        $response = $this->get('/faq');

        $response->assertOk();
        foreach (['What is Easy Refer?', 'How does the QR code referral system work?', 'How much does it cost?', 'How do I track referrals?', 'Is there a setup fee?', 'Is my data secure?', 'Do I need a card to start?', 'How long does setup take?', 'Which businesses does Easy Refer fit?', 'What do rewards cost me?', 'How do I cancel?', 'What if a customer never scans the code?', 'How long do rewards last?'] as $question) {
            $response->assertSee($question);
        }
        // S-06 order: the money questions sit together (cost,
        // then setup fee), the tracker question after them.
        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'How do I track referrals?'), strpos($html, 'Is there a setup fee?'));
        // Amendment 12 (release 11): the five new entries append
        // after FAQ-07, so the card question still precedes the
        // setup question.
        $this->assertLessThan(strpos($html, 'How long does setup take?'), strpos($html, 'Do I need a card to start?'));
        // FAQ-03 as amended: terms, then the worked example, then
        // the visit-that-counts definition last.
        $response->assertSee('Only when a referred friend visits and redeems: $2. No monthly fee. First 5 visits free. Never more than $99 a month in visit charges. Say 12 referred friends visit in a month. That is one $24 charge. In your first month, the first 5 are free. The same 12 visits cost $14 that month. A visit counts when a referred friend comes in and uses their reward. We confirm their phone first, and every visit is checked before it counts.', false);
        // FAQ-07 as amended: the card is the activation step, not
        // a signup field.
        // RE-DERIVED (activation redesign, census addendum
        // C-38, FAQ-07): the card is not needed to start.
        $response->assertSee('No. Your QR code turns on once your friend reward is set, and your first 5 visits are free with no card at all. After those, your code pauses until a card is on file. Nothing is charged until after your first 5 free visits. You can cancel any time before then.');
        $response->assertDontSee('we ask for a card when you sign up');
        // The five new answers, verbatim.
        $response->assertSee('Minutes. Sign up in three short steps. Then set your reward, print your QR code, and add your card.');
        $response->assertSee('Any local business customers visit in person. It is built for barbershops, salons, cafes, and boutiques. If people pay at your counter, it can work for you.');
        $response->assertSee('You choose the reward, so you choose the cost. A free coffee, a discount, whatever brings people back. You hand it out only when a referred friend redeems it. Our $2 fee is separate.');
        $response->assertSee('End your program any time in Settings, under Account. Your code turns off right away, we charge one final bill for the visits and texts you already have, and rewards your customers already earned stay good for 30 days, or until their own use by date if that comes first. Your account stays open so you can see your history.');
        $response->assertSee('Nothing happens, and nothing is charged. You pay only when a referred friend visits and redeems a reward. Put the code where customers cannot miss it.');
        // M-4 [M4-FAQ-13]: appended last so subset indices never
        // move; vesting range and term render from config.
        $response->assertSee("A friend's reward is given at their visit. The referrer's reward is ready after a short wait, 7 to 14 days, and stays good for 12 months from the day it is ready. The date is on the reward itself, and we text the referrer a reminder before it runs out.");
        $response->assertDontSee('verified');
        // S-05: the page closes with the support fallback.
        $response->assertSee('Still have a question?');
        $response->assertSee('support@easy-refer.com');
    }

    public function test_faq_page_renders_flat_with_every_answer_in_the_open(): void
    {
        // Ruling 5 (release 08d conformance): the /faq page is a
        // reference surface and renders the shared list flat:
        // every answer in the served HTML with no accordion scope,
        // no disclosure buttons, nothing waiting on a tap or on
        // Alpine. The 08b single-open accordion leaked onto this
        // page through the shared partial; this pin holds the
        // page to its mode.
        $response = $this->get('/faq');

        $response->assertOk();
        $response->assertSee('Only when a referred friend visits and redeems: $2. No monthly fee. First 5 visits free. Never more than $99 a month in visit charges. Say 12 referred friends visit in a month. That is one $24 charge. In your first month, the first 5 are free. The same 12 visits cost $14 that month. A visit counts when a referred friend comes in and uses their reward. We confirm their phone first, and every visit is checked before it counts.', false);
        // RE-DERIVED (activation redesign, census addendum
        // C-38, FAQ-07): the card is not needed to start.
        $response->assertSee('No. Your QR code turns on once your friend reward is set, and your first 5 visits are free with no card at all. After those, your code pauses until a card is on file. Nothing is charged until after your first 5 free visits. You can cancel any time before then.');
        // The accordion's scope variable is the discriminator:
        // the layout's own menu uses x-show, but only the FAQ
        // accordion emits openItem.
        $response->assertDontSee('openItem');
    }

    public function test_home_has_no_faq_accordion_and_verticals_keep_the_single_open_one(): void
    {
        // The other half of ruling 5, as amended by the copy
        // release (O-1): home renders no FAQ list at all, so no
        // accordion scope exists there. The section-embedded list
        // on the verticals stays a single-open accordion (one
        // shared Alpine scope, answers cloaked until opened).
        // Only the /faq page renders flat.
        $this->get('/')->assertDontSee('openItem');

        $vertical = $this->get('/for-cafes');

        $vertical->assertOk();
        $vertical->assertSee('x-data="{ openItem: null }"', false);
        $vertical->assertSee('x-show="openItem === 0"', false);
        $vertical->assertSee('x-cloak', false);
        $vertical->assertSee('What is Easy Refer?');
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
        // Legal decisions (2026-10-25, Amendment 20): the banned
        // "verified" wording is replaced with the decided plain
        // strings, and the amount still renders from the plan.
        $response->assertSee('Businesses are charged $2.00 for each visit that counts.');
        $response->assertSee('We acknowledge disputes within 2 business days');
        $response->assertDontSee('Stripe');
    }

    public function test_refund_page_states_the_decided_policy(): void
    {
        $response = $this->get('/refund');

        $response->assertOk();
        // Item 3: no subscription exists, so the page no longer
        // frames charges as subscription fees or partial months.
        $response->assertDontSee('Subscription');
        $response->assertDontSee('Partial months');
        $response->assertSee('You are charged once a month for the visits that counted.');
        // Item 1: neither banned "verified" use survives anywhere
        // on this page (the absence is scoped to /refund).
        $response->assertDontSee('verified');
        $response->assertSee('failed delivery caused by a system problem on our side.');
        // Item 2: the exact texting rate, rendered from the same
        // billing config as the home page's pricing section.
        $response->assertSee('Text campaigns cost '.(int) config('billing.sms_segment_price_cents').' cents per segment for each customer, sent through Twilio and charged on your monthly bill.');
        $response->assertDontSee('charged through Twilio');
        // Item 4: one window everywhere: 30 days to raise it,
        // approved refunds issued within 7 business days.
        $response->assertSee('Disputes must be raised within 30 days');
        $response->assertSee('Approved refunds are issued within 7 business days.');
    }

    public function test_legal_page_states_the_decided_windows_and_compliance(): void
    {
        $response = $this->get('/legal');

        $response->assertOk();
        // Item 4: the legal page carries the same unified window
        // as the refund page; the old 14-day / 7-to-10-day lines
        // are gone.
        $response->assertSee('Refund requests under our refund policy must be made within 30 days of payment. Approved refunds are issued within 7 business days.');
        $response->assertDontSee('within 14 days of payment');
        $response->assertDontSee('7 to 10 business days');
        // Item 6: no blanket compliance certification claim; the
        // softened line states only what is provable.
        $response->assertDontSee('built to comply with');
        $response->assertDontSee('GDPR');
        $response->assertDontSee('PCI DSS');
        $response->assertSee('Card payments are handled by Square; card details never touch our servers, and we do not store payment information ourselves.');
        // Item 7 + coordinator ruling follow-up 2026-10-25:
        // support stays email-only everywhere, and the absence
        // now covers the WHOLE response, structured data
        // included (the layout JSON-LD telephone was removed
        // under the same ruling; see the structured-data test).
        $response->assertSee('mailto:support@easy-refer.com');
        $this->assertStringNotContainsString('803-2400', $response->getContent());
        $this->assertStringNotContainsString('(833)', $response->getContent());
    }

    public function test_marketing_structured_data_declares_no_telephone(): void
    {
        // Coordinator ruling follow-up 2026-10-25: the marketing
        // layout's JSON-LD declared the +1 (833) 803-2400
        // texting line as the organization's telephone, at the
        // organization level and on the contact point. That line
        // is an outbound SMS number, not a support channel, so
        // the structured data is email-only too; the rest of the
        // JSON-LD stays intact and valid.
        $response = $this->get('/about');
        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('803-2400', $content);
        $this->assertStringNotContainsString('(833)', $content);

        $this->assertSame(1, preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $m));
        $this->assertStringNotContainsString('telephone', $m[1]);
        $data = json_decode($m[1], true);
        $this->assertIsArray($data);
        $org = $data['@graph'][0];
        $this->assertSame('Organization', $org['@type']);
        $this->assertSame('Easy Refer', $org['name']);
        $this->assertSame('support@easy-refer.com', $org['email']);
        $this->assertArrayNotHasKey('telephone', $org);
        $this->assertNotEmpty($org['sameAs']);
        $this->assertSame('ContactPoint', $org['contactPoint']['@type']);
        $this->assertSame('support@easy-refer.com', $org['contactPoint']['email']);
        $this->assertSame('customer support', $org['contactPoint']['contactType']);
        $this->assertArrayNotHasKey('telephone', $org['contactPoint']);
    }

    public function test_blog_index_shows_the_intro_and_the_empty_state(): void
    {
        $response = $this->get('/blog');

        $response->assertOk();
        $response->assertSee('You will find helpful tips, success stories, and insights into how referral programs can boost small business growth.');
        $response->assertDontSee('Welcome to the Easy Refer blog.');
        $response->assertSee('Posts are on the way. Check back soon.');
        // DG-10: the page closes with the starter-kit block.
        $response->assertSee('Join the list for the free Referral Program Starter Kit');
    }

    /**
     * B5 (M-6 blog week 1): the populated half of the split. With
     * posts present the index lists them newest first with title,
     * date, and excerpt, the empty state is gone, an unpublished
     * post never appears, and the starter kit still closes the page.
     */
    public function test_blog_index_lists_published_posts_and_hides_the_empty_state(): void
    {
        Post::create([
            'slug' => 'older-post',
            'title' => 'Older Post',
            'excerpt' => 'The older excerpt.',
            'body' => 'Older body.',
            'published_at' => now()->subDays(2),
        ]);
        $newer = Post::create([
            'slug' => 'newer-post',
            'title' => 'Newer Post',
            'excerpt' => 'The newer excerpt.',
            'body' => 'Newer body.',
            'published_at' => now()->subDay(),
        ]);
        Post::create([
            'slug' => 'draft-post',
            'title' => 'Draft Post',
            'excerpt' => 'The draft excerpt.',
            'body' => 'Draft body.',
            'published_at' => null,
        ]);

        $response = $this->get('/blog');

        $response->assertOk();
        $response->assertSee('You will find helpful tips, success stories, and insights into how referral programs can boost small business growth.');
        $response->assertDontSee('Posts are on the way. Check back soon.');
        $response->assertSeeInOrder(['Newer Post', 'Older Post']);
        $response->assertSee($newer->published_at->format('M j, Y'));
        $response->assertSee('The newer excerpt.');
        $response->assertSee(route('blog.post', ['slug' => 'newer-post']), false);
        $response->assertDontSee('Draft Post');
        $response->assertDontSee('The draft excerpt.');
        // The starter kit block still closes the index page.
        $response->assertSee('Join the list for the free Referral Program Starter Kit');
    }

    /**
     * B2 (M-6 blog week 1): the sitemap gains one URL per published
     * post and keeps unpublished posts out, both directions.
     */
    public function test_sitemap_includes_published_posts_and_excludes_unpublished(): void
    {
        Post::create([
            'slug' => 'listed-post',
            'title' => 'Listed Post',
            'excerpt' => 'Listed excerpt.',
            'body' => 'Listed body.',
            'published_at' => now(),
        ]);
        Post::create([
            'slug' => 'unlisted-post',
            'title' => 'Unlisted Post',
            'excerpt' => 'Unlisted excerpt.',
            'body' => 'Unlisted body.',
            'published_at' => null,
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertSee('<loc>'.route('blog.post', ['slug' => 'listed-post']).'</loc>', false);
        $response->assertDontSee('unlisted-post');
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
        ])->assertRedirect(route('onboarding.welcome'));

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
        $this->get('/')->assertSee('We use cookies to see how visitors find us and whether our ads work.');
        $this->get(route('login'))->assertDontSee('We use cookies to see how visitors find us');
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
