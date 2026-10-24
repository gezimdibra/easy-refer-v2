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
            'objection_answer' => 'Rebooking keeps your clients. Referrals add new ones: your clients share their link, and their friends book with you.',
            'visual' => ['kind' => 'poster', 'base' => 'images/poster-qr', 'width' => 480, 'height' => 398, 'alt' => 'The QR code page in the owner dashboard, showing the large QR code customers scan and the poster downloads.'],
        ],
        'cafes' => [
            'type' => 'cafe',
            'h1' => 'Your regulars bring your cafe its next customers',
            'line' => 'Built for cafes: your morning regulars know everyone on the block.',
            'objection' => 'Margins are too thin for discounts',
            'objection_answer' => 'You choose the reward, and you pay our {per_visit} only when a new customer actually visits and redeems.',
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
        return view('marketing.home', [
            'plan' => Plan::launch(),
            // The pricing FAQ row (landing-page-spec.md Section
            // 4.7): FAQ-01, FAQ-02, FAQ-03, FAQ-05.
            'faqs' => $this->faqSubset(),
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
     * The seven approved FAQ entries (inventory FAQ-01 to FAQ-07,
     * in the release 08b S-06 order: money questions together at
     * positions 3 and 4, FAQ-07 last and FAQ page only), with the
     * pricing answers' amounts interpolated from the plan table
     * and config so the page can never drift from the plan row.
     *
     * @return list<array{q: string, a: string}>
     */
    private function faqs(): array
    {
        $plan = Plan::launch();
        $perVisit = Money::usd($plan->per_referral_cents);
        $cap = Money::usd($plan->monthly_cap_cents);
        $free = (int) config('billing.free_visits_at_signup');

        return [
            [
                'q' => 'What is Easy Refer?',
                'a' => 'Easy Refer is a referral platform for local businesses. Customers scan a QR code, share their referral link, and earn rewards when friends visit. You pay only for verified visits, and you can send bulk texts from your dashboard.',
            ],
            [
                'q' => 'How does the QR code referral system work?',
                'a' => 'Your business gets its own QR code for the counter. A customer scans it, verifies their phone number, and gets a personal link to share. When a friend visits through that link, both of them can claim a reward.',
            ],
            [
                'q' => 'How much does it cost?',
                'a' => "You pay {$perVisit} only when a referred friend actually visits your store and redeems. There is no monthly fee. Your first {$free} verified visits are free, and you never pay more than {$cap} in a month.",
            ],
            [
                'q' => 'Is there a setup fee?',
                'a' => "No. There is no setup fee and no monthly fee. You pay only per verified referral visit, and your first {$free} verified visits are free.",
            ],
            [
                'q' => 'How do I track referrals?',
                'a' => 'The owner dashboard shows your referrals, rewards, customers, and campaign results in real time.',
            ],
            [
                'q' => 'Is my data secure?',
                'a' => 'Yes. Passwords are stored with strong one-way hashing, sessions expire on their own, and card details never touch our servers: Square handles payments and Twilio handles text messages. You can ask for a copy or deletion of your data any time at support@easy-refer.com.',
            ],
            [
                'q' => 'Do I need a card to start?',
                'a' => "Yes, we ask for a card when you sign up so billing can start automatically later. Nothing is charged until after your first {$free} free verified visits, and you can cancel any time before then.",
            ],
        ];
    }

    /**
     * The home and vertical pages' FAQ subset (spec Section 2.6):
     * FAQ-01, FAQ-02, FAQ-03, FAQ-05, which sit at indices 0 to 3
     * after the release 08b S-06 reorder (01, 02, 03, 05, 04, 06,
     * then FAQ-07, FAQ page only).
     *
     * @return list<array{q: string, a: string}>
     */
    private function faqSubset(): array
    {
        $faqs = $this->faqs();

        return [$faqs[0], $faqs[1], $faqs[2], $faqs[3]];
    }
}
