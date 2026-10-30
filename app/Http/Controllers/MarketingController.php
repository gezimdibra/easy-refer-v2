<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Post;
use App\Support\Money;
use Illuminate\View\View;

/**
 * The public marketing pages (spec Section 2). Every string on these
 * pages comes from the approved copy inventory; pricing renders from
 * the plans table everywhere it appears, and OD-6 claims (stats,
 * testimonials, counts, founding year) render only as the approved
 * set, from the single-source claims values in config/claims.php
 * (OD-6 closed 2026-10-23, AP-07).
 */
class MarketingController extends Controller
{
    /**
     * The vertical landing content map (spec Section 2.6, inventory
     * LAND-B1/S1/C1/T1): only the vertical nouns, the objection
     * answer, and the signup type change between variants.
     *
     * @var array<string, array{type: string, h1: string, line: string, objection: string, objection_answer: string}>
     */
    private const VERTICALS = [
        'barbers' => [
            'type' => 'barber',
            'h1' => 'Your regulars bring your barbershop its next clients',
            'line' => 'Built for barbershops: the client in your chair is your best advertising.',
            'objection' => 'My chairs are already busy on Saturdays',
            'objection_answer' => 'Referrals fill weekdays. Your regulars send friends, and friends book the quiet days too.',
            'visual' => ['kind' => 'poster', 'base' => 'images/poster-counter', 'width' => 480, 'height' => 480, 'alt' => 'The printed counter poster for a barbershop: a large QR code and the words Scan this code to join and start referring.'],
        ],
        'salons' => [
            'type' => 'salon',
            'h1' => 'Your regulars bring your salon its next clients',
            'line' => 'Built for salons: every compliment on your work is a referral waiting to happen.',
            'objection' => 'My clients already rebook',
            'objection_answer' => 'Rebooking keeps your clients. Referrals add new ones. Your clients share their link, and their friends book with you.',
            'visual' => ['kind' => 'poster', 'base' => 'images/poster-qr', 'width' => 480, 'height' => 398, 'alt' => 'The QR code page in the owner dashboard, showing the large QR code customers scan and the poster downloads.'],
        ],
        'cafes' => [
            'type' => 'cafe',
            'h1' => 'Your regulars bring your cafe its next customers',
            'line' => 'Built for cafes: your morning regulars know everyone on the block.',
            'objection' => 'Margins are too thin for discounts',
            'objection_answer' => 'You pick the reward. You pay {per_visit} only when a new customer visits and redeems.',
            'visual' => ['kind' => 'photo', 'base' => 'images/photos/ungaro-hero', 'width' => 1080, 'height' => 720, 'alt' => 'A printed Easy Refer referral sign with a QR code on the counter at Ungaro Coal Fired Pizza Cafe.'],
        ],
        'boutiques' => [
            'type' => 'boutique',
            'h1' => 'Your regulars bring your boutique its next customers',
            'line' => 'Built for boutiques: every "where did you get that?" is a referral waiting to happen.',
            'objection' => 'I am not a discount brand',
            'objection_answer' => 'The reward can be early access or a gift. It never has to be a price cut.',
            'visual' => ['kind' => 'photo', 'base' => 'images/photos/pharmacy-hero', 'width' => 1080, 'height' => 720, 'alt' => 'A printed Easy Refer referral poster with a QR code at a pharmacy counter in Albania.'],
        ],
    ];

    public function home(): View
    {
        // Copy release (2026-10-24, O-1): home no longer renders
        // the FAQ subset; the full set lives on /faq. The
        // verticals still pass faqSubset() below.
        return view('marketing.home', [
            'plan' => Plan::launch(),
            // G6 demo (Amendment 63): the demo block renders its
            // resting line instead of the frame when starts are
            // paused (the design pass's resting state).
            'demoResting' => app(\App\Services\Demo\DemoService::class)->resting(),
        ]);
    }

    public function vertical(string $vertical): View
    {
        $content = self::VERTICALS[$vertical] ?? abort(404);
        $plan = Plan::launch();

        // The one amount inside vertical copy (LAND-C1) renders from
        // the plan table like every other price on the page.
        $content['objection_answer'] = str_replace(
            '{per_visit}',
            Money::usd($plan->per_referral_cents),
            $content['objection_answer'],
        );

        return view('marketing.vertical', [
            'plan' => $plan,
            'vertical' => $content,
            'faqs' => $this->faqSubset(),
        ]);
    }

    public function about(): View
    {
        return view('marketing.about');
    }

    /**
     * The sitemap (landing-page-spec.md Section 7): the public
     * marketing routes only, generated from the route table so it
     * cannot drift from what actually exists.
     */
    public function sitemap(): \Illuminate\Http\Response
    {
        $urls = [
            route('home'),
            route('about'),
            route('faq'),
            route('legal'),
            route('refund'),
            route('blog'),
        ];

        foreach (array_keys(self::VERTICALS) as $vertical) {
            $urls[] = route('vertical', ['vertical' => $vertical]);
        }

        // B2 (M-6 blog week 1): published posts are public URLs
        // too. Unpublished posts stay out, exactly as they stay
        // off the index and 404 at their own address.
        foreach (Post::query()->whereNotNull('published_at')->orderByDesc('published_at')->get() as $post) {
            $urls[] = route('blog.post', ['slug' => $post->slug]);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .implode('', array_map(
                fn (string $url) => '  <url><loc>'.e($url).'</loc></url>'."\n",
                $urls,
            ))
            .'</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * robots.txt, generated so the Sitemap line follows the app URL
     * config at either mount (a static file cannot). The static
     * public/robots.txt was removed so this route is what serves.
     */
    public function robots(): \Illuminate\Http\Response
    {
        $body = "User-agent: *\nDisallow:\n\nSitemap: ".route('sitemap')."\n";

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }

    public function faq(): View
    {
        return view('marketing.faq', ['faqs' => $this->faqs()]);
    }

    public function legal(): View
    {
        // The privacy data inventory is generated from the shipped
        // configuration (spec Section 2.4): the optional customer
        // email clause appears only when the OD-10 field is enabled
        // in that same configuration.
        $collectsCustomerEmail = (bool) config('referrals.customer_email_field');

        return view('marketing.legal', [
            'customerDataInventory' => 'name, phone number, and usage records'
                .($collectsCustomerEmail ? ', plus email (optional)' : ''),
        ]);
    }

    public function refund(): View
    {
        return view('marketing.refund', ['plan' => Plan::launch()]);
    }

    public function blog(): View
    {
        return view('marketing.blog', [
            'posts' => Post::query()
                ->whereNotNull('published_at')
                ->orderByDesc('published_at')
                ->get(),
        ]);
    }

    public function blogPost(string $slug): View
    {
        $post = Post::query()
            ->where('slug', $slug)
            ->whereNotNull('published_at')
            ->firstOrFail();

        return view('marketing.blog-post', ['post' => $post]);
    }

    /**
     * The thirteen approved FAQ entries (inventory FAQ-01 to
     * FAQ-12 plus M-4's FAQ-13 appended last, in the release 08b S-06 order: money questions
     * together at positions 3 and 4, FAQ-07 last of the original
     * set and FAQ page only), with the pricing answers' amounts
     * interpolated from the plan table and config so the page can
     * never drift from the plan row. Amendment 12 (release 11,
     * 2026-10-24) extends the FAQ-03 answer with the worked
     * example and the visit-that-counts definition, amends
     * FAQ-07, and appends FAQ-08 to FAQ-12 after FAQ-07 so the
     * vertical subset indices never move. The worked example's
     * figures are computed from the same plan values: twelve
     * visits at the per-visit price, and the same twelve in the
     * first month with the one-time free allowance subtracted.
     *
     * @return list<array{q: string, a: string}>
     */
    private function faqs(): array
    {
        $plan = Plan::launch();
        $perVisit = Money::usd($plan->per_referral_cents);
        $cap = Money::usd($plan->monthly_cap_cents);
        $free = (int) config('billing.free_visits_at_signup');
        $exampleVisits = 12;
        $exampleCharge = Money::usd($exampleVisits * $plan->per_referral_cents);
        $exampleFirstMonth = Money::usd(($exampleVisits - $free) * $plan->per_referral_cents);

        return [
            [
                'q' => 'What is Easy Refer?',
                'a' => 'Easy Refer turns your customers into referrers. They scan a QR code, share their link, and earn rewards when friends visit. You pay only for visits that count.',
            ],
            [
                'q' => 'How does the QR code referral system work?',
                'a' => 'Your shop gets its own QR code. A customer scans it, confirms their phone, and gets a link to share. When a friend visits, both earn a reward.',
            ],
            [
                'q' => 'How much does it cost?',
                'a' => "Only when a referred friend visits and redeems: {$perVisit}. No monthly fee. First {$free} visits free. Never more than {$cap} a month in visit charges. Say {$exampleVisits} referred friends visit in a month. That is one {$exampleCharge} charge. In your first month, the first {$free} are free. The same {$exampleVisits} visits cost {$exampleFirstMonth} that month. A visit counts when a referred friend comes in and uses their reward. We confirm their phone first, and every visit is checked before it counts.",
            ],
            [
                'q' => 'Is there a setup fee?',
                'a' => "No. No setup fee, no monthly fee. You pay per visit, and your first {$free} visits are free.",
            ],
            [
                'q' => 'How do I track referrals?',
                'a' => 'The owner dashboard shows your referrals, rewards, customers, and campaign results.',
            ],
            [
                'q' => 'Is my data secure?',
                'a' => 'Yes. Passwords are stored with strong one-way hashing. Sessions expire on their own. Card details never touch our servers: Square handles payments and Twilio handles text messages. You can ask for a copy or deletion of your data any time at support@easy-refer.com.',
            ],
            [
                'q' => 'Do I need a card to start?',
                'a' => "No. Your QR code turns on once your friend reward is set, and your first {$free} visits are free with no card at all. After those, your code pauses until a card is on file. Nothing is charged until after your first {$free} free visits. You can cancel any time before then.",
            ],
            [
                'q' => 'How long does setup take?',
                'a' => 'Minutes. Sign up in three short steps. Then set your reward, print your QR code, and add your card.',
            ],
            [
                'q' => 'Which businesses does Easy Refer fit?',
                'a' => 'Any local business customers visit in person. It is built for barbershops, salons, cafes, and boutiques. If people pay at your counter, it can work for you.',
            ],
            [
                'q' => 'What do rewards cost me?',
                'a' => "You choose the reward, so you choose the cost. A free coffee, a discount, whatever brings people back. You hand it out only when a referred friend redeems it. Our {$perVisit} fee is separate.",
            ],
            [
                'q' => 'How do I cancel?',
                // M-4 [M4-FAQ-11]: the branched closure clause —
                // a reward whose own use-by date falls inside the
                // 30 days ends on its own date, so the unbranched
                // sentence would be false for it.
                'a' => 'End your program any time in Settings, under Account. Your code turns off right away, we charge one final bill for the visits and texts you already have, and rewards your customers already earned stay good for 30 days, or until their own use by date if that comes first. Your account stays open so you can see your history.',
            ],
            [
                'q' => 'What if a customer never scans the code?',
                'a' => 'Nothing happens, and nothing is charged. You pay only when a referred friend visits and redeems a reward. Put the code where customers cannot miss it.',
            ],
            [
                // M-4 [M4-FAQ-13]: appended LAST so the subset
                // indices above never move. The vesting range and
                // the expiry term render from config, never
                // hand-typed numbers (the FAQ-03 discipline).
                'q' => 'How long do rewards last?',
                'a' => "A friend's reward is given at their visit. The referrer's reward is ready after a short wait, ".(int) config('referrals.vesting_days_min').' to '.(int) config('referrals.vesting_days_max').' days, and stays good for '.(int) config('referrals.reward_expiry_months', 12).' months from the day it is ready. The date is on the reward itself, and we text the referrer a reminder before it runs out.',
            ],
        ];
    }

    /**
     * The vertical pages' FAQ subset (spec Section 2.6):
     * FAQ-01, FAQ-02, FAQ-03, FAQ-05, which sit at indices 0 to 3
     * after the release 08b S-06 reorder (01, 02, 03, 05, 04, 06,
     * then FAQ-07, FAQ page only). Home carried the same subset
     * until the copy release removed it (O-1, 2026-10-24).
     *
     * @return list<array{q: string, a: string}>
     */
    private function faqSubset(): array
    {
        $faqs = $this->faqs();

        return [$faqs[0], $faqs[1], $faqs[2], $faqs[3]];
    }
}
