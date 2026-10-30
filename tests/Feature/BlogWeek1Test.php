<?php

namespace Tests\Feature;

use App\Models\Post;
use Database\Seeders\BlogWeek1Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M-6 Blog Week 1 (Amendment 73; design pass
 * hidden_files/night-loop/m6-blog-week1-design-pass.md Section 6.1):
 * B1 the post-page call to action with its per-post UTM links, the
 * unpublished post's invisibility, the visible-text sweep on the
 * post page (VT-01 discipline), and the BlogWeek1Seeder's
 * idempotency plus its byte-for-byte body contract.
 */
class BlogWeek1Test extends TestCase
{
    use RefreshDatabase;

    private function makePost(string $slug, ?\DateTimeInterface $publishedAt = null, string $body = 'First paragraph copy.'): Post
    {
        return Post::create([
            'slug' => $slug,
            'title' => 'Post '.$slug,
            'excerpt' => 'Excerpt for '.$slug.'.',
            'body' => $body."\n\n".'Second paragraph copy.',
            'published_at' => $publishedAt ?? now(),
        ]);
    }

    /**
     * Every href on the page, entity-decoded, so a UTM assertion
     * compares real URLs instead of their HTML-escaped form.
     *
     * @return list<string>
     */
    private function hrefs(string $html): array
    {
        preg_match_all('/href="([^"]+)"/', $html, $matches);

        return array_map(
            static fn (string $href) => html_entity_decode($href, ENT_QUOTES, 'UTF-8'),
            $matches[1],
        );
    }

    public function test_blog_post_page_renders_the_cta_block_with_the_posts_utm_links(): void
    {
        $this->makePost('pay-only-when-a-customer-walks-in');

        $response = $this->get(route('blog.post', ['slug' => 'pay-only-when-a-customer-walks-in']));

        $response->assertOk();
        // BLOG-03 heading, BLOG-04 offer line (free count from
        // config, as the FAQ pricing answers render it), and the
        // two action labels.
        $response->assertSee('Ready to bring customers in?');
        $response->assertSee('Your first '.(int) config('billing.free_visits_at_signup').' visits that count are free, with no monthly fee and no setup fee. You pay only when a referred friend walks in and uses their reward.');
        $response->assertSee('Try the demo');
        $response->assertSee('Start free');
        $response->assertSee('Back to the blog');

        $utm = [
            'utm_source' => 'blog',
            'utm_medium' => 'content',
            'utm_campaign' => 'blog-week1',
            'utm_content' => 'pay-only-when-a-customer-walks-in',
        ];
        $hrefs = $this->hrefs($response->getContent());
        $this->assertContains(route('demo.show', $utm), $hrefs);
        $this->assertContains(route('register', $utm), $hrefs);
    }

    public function test_blog_cta_links_carry_each_posts_own_slug_in_utm_content(): void
    {
        $this->makePost('first-week-post');
        $this->makePost('second-week-post');

        foreach (['first-week-post', 'second-week-post'] as $slug) {
            $hrefs = $this->hrefs($this->get(route('blog.post', ['slug' => $slug]))->getContent());

            $demo = array_values(array_filter($hrefs, static fn (string $href) => str_contains($href, '/demo?')));
            $register = array_values(array_filter($hrefs, static fn (string $href) => str_contains($href, 'utm_content=') && str_contains($href, '/auth/register?')));
            $this->assertNotEmpty($demo, $slug);
            $this->assertNotEmpty($register, $slug);
            foreach (array_merge($demo, $register) as $href) {
                $this->assertStringContainsString('utm_source=blog', $href);
                $this->assertStringContainsString('utm_medium=content', $href);
                $this->assertStringContainsString('utm_campaign=blog-week1', $href);
                $this->assertStringContainsString('utm_content='.$slug, $href);
            }
        }

        // Both directions: neither page carries the other post's slug.
        $first = $this->get(route('blog.post', ['slug' => 'first-week-post']))->getContent();
        $this->assertStringNotContainsString('utm_content=second-week-post', $first);
    }

    public function test_unpublished_post_stays_404_and_off_the_index(): void
    {
        $this->makePost('hidden-draft', null)->forceFill(['published_at' => null])->save();
        $this->makePost('visible-post');

        $this->get(route('blog.post', ['slug' => 'hidden-draft']))->assertNotFound();

        $index = $this->get(route('blog'));
        $index->assertOk();
        $index->assertSee('Post visible-post');
        $index->assertDontSee('Post hidden-draft');
        $index->assertDontSee('Excerpt for hidden-draft.');
    }

    public function test_blog_post_page_visible_text_has_no_leaked_markup_or_banned_copy(): void
    {
        $this->makePost('sweep-post');

        $html = $this->get(route('blog.post', ['slug' => 'sweep-post']))->getContent();

        // Visible text only: scripts, styles, and every tag (with
        // its attributes, where the UTMs legitimately live) removed.
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/s', ' ', $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        // The real strings are all present as visible text.
        foreach ([
            'Post sweep-post',
            'First paragraph copy.',
            'Second paragraph copy.',
            'Ready to bring customers in?',
            'Try the demo',
            'Start free',
            'Back to the blog',
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        // Nothing that is markup, template syntax, a class name, or
        // a query parameter leaks into the visible text (VT-01).
        foreach (['{{', '}}', '@foreach', '@endforeach', 'preg_split', 'utm_source', 'utm_medium', 'utm_content', 'btn', 'border-line', 'type-title'] as $leaked) {
            $this->assertStringNotContainsString($leaked, $text, 'leaked into visible text: '.$leaked);
        }

        // Copy rules on the whole rendered page: no em dashes, no
        // banned wording, no unapproved support claim.
        $this->assertStringNotContainsString("\u{2014}", $html);
        $this->assertStringNotContainsString('verified visit', mb_strtolower($html));
        $this->assertStringNotContainsString('24/7', $html);
    }

    public function test_blog_week1_seeder_seeds_only_posts_01_and_02_and_is_idempotent(): void
    {
        $this->seed(BlogWeek1Seeder::class);

        $this->assertSame(2, Post::count());
        $slugs = Post::query()->orderBy('slug')->pluck('slug')->all();
        $this->assertSame(['how-a-referral-program-works', 'pay-only-when-a-customer-walks-in'], $slugs);
        // Posts 03 and 04 are identity briefs: never seeded as stubs.
        $this->assertDatabaseMissing('posts', ['slug' => 'barbershop-referral-ideas-weekday-chairs']);
        $this->assertDatabaseMissing('posts', ['slug' => 'picking-a-referral-reward-that-fits-your-ticket']);
        foreach (Post::all() as $post) {
            $this->assertNotNull($post->published_at);
        }

        $firstRun = Post::query()->orderBy('slug')->get(['slug', 'title', 'excerpt', 'body', 'published_at'])
            ->map(static fn (Post $post) => $post->getAttributes())->all();

        // A second run converges to the same rows: same count, same
        // content, and the original published_at preserved.
        $this->seed(BlogWeek1Seeder::class);

        $this->assertSame(2, Post::count());
        $secondRun = Post::query()->orderBy('slug')->get(['slug', 'title', 'excerpt', 'body', 'published_at'])
            ->map(static fn (Post $post) => $post->getAttributes())->all();
        $this->assertSame($firstRun, $secondRun);
    }

    public function test_blog_week1_seeder_bodies_match_the_drafts_byte_for_byte(): void
    {
        // The programmatic verbatim diff, frozen as hashes taken
        // from the draft files at build time (design pass Section
        // 6.1 item 3: a hand-retyped body is a copy defect).
        $expectedHashes = [
            'pay-only-when-a-customer-walks-in' => '180d3d808673ceb618e4c6d1fca80c5ca6d4c1143cf8ed8447e08f784dcc509a',
            'how-a-referral-program-works' => 'f809d38aa3692a37021e0a859c479b3b36ea6dcb95b6555d989bb020df3e8d93',
        ];
        $draftFiles = [
            'pay-only-when-a-customer-walks-in' => '01-pay-only-when-a-customer-walks-in.md',
            'how-a-referral-program-works' => '02-how-a-referral-program-works.md',
        ];

        $definitions = collect(BlogWeek1Seeder::definitions())->keyBy('slug');
        $this->assertSame(
            ['how-a-referral-program-works', 'pay-only-when-a-customer-walks-in'],
            $definitions->keys()->sort()->values()->all(),
        );

        foreach ($expectedHashes as $slug => $hash) {
            $body = $definitions[$slug]['body'];
            $this->assertSame($hash, hash('sha256', $body), $slug.' body drifted from its draft');

            // When the goal tree is beside this code tree (the
            // workspace and merged-main layout), diff the body
            // against the draft file itself, not only its hash.
            $draftPath = base_path('../hidden_files/night-loop/m6-posts/'.$draftFiles[$slug]);
            if (is_file($draftPath)) {
                $draft = (string) file_get_contents($draftPath);
                $this->assertSame(1, preg_match('/## BODY START\n\n(.*?)\n\n## BODY END/s', $draft, $matches), $slug);
                $this->assertSame($matches[1], $body, $slug.' body differs from the draft file');
            }
        }

        // And what the seeder stores is exactly its definitions.
        $this->seed(BlogWeek1Seeder::class);
        foreach ($definitions as $slug => $definition) {
            $stored = Post::query()->where('slug', $slug)->firstOrFail();
            $this->assertSame($definition['title'], $stored->title);
            $this->assertSame($definition['excerpt'], $stored->excerpt);
            $this->assertSame($definition['body'], $stored->body);
        }
    }
}
