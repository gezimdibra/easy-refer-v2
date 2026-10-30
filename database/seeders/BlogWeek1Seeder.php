<?php

namespace Database\Seeders;

use App\Models\Post;
use Illuminate\Database\Seeder;

/**
 * M-6 Blog Week 1 (Amendment 73; design pass
 * hidden_files/night-loop/m6-blog-week1-design-pass.md Section 6.1
 * item 3): seeds ONLY Posts 01 and 02, verbatim from the approved
 * drafts in hidden_files/night-loop/m6-posts/. Posts 03 and 04 are
 * identity briefs in that folder and are NEVER seeded from here:
 * a seeded stub would be a thin page.
 *
 * Content is not schema, so this is a seeder, NOT a data migration
 * (a migration would also put the posts into every fresh test
 * database). It is run standalone, the way PlanSeeder and the demo
 * seeder are run: php artisan db:seed --class=BlogWeek1Seeder.
 *
 * Idempotent, keyed on slug: a re-run converges to the same two
 * rows with the same bodies and never duplicates them. The first
 * run sets published_at at run time; a re-run preserves the
 * original published_at, so the date line a reader sees never
 * moves under them.
 *
 * The bodies below are the draft bodies byte-for-byte: paragraphs
 * separated by one blank line, plain text only. The post view
 * splits the body on blank lines and prints each block as one
 * escaped plain paragraph, so there are no headings, no lists,
 * no links, and no markup inside a body, by design. The verbatim
 * check lives in tests/Feature/BlogWeek1Test.php, which diffs
 * these definitions against the draft files programmatically
 * and pins their SHA-256 hashes.
 */
class BlogWeek1Seeder extends Seeder
{
    /**
     * The Week 1 seed records (Posts 01 and 02 only).
     *
     * @return list<array{slug: string, title: string, excerpt: string, body: string}>
     */
    public static function definitions(): array
    {
        return [
        [
            'slug' => 'pay-only-when-a-customer-walks-in',
            'title' => 'You should not pay for a customer who never walks in',
            'excerpt' => 'Ads and monthly tools charge you before anyone shows up. There is a simpler test for any marketing cost: did a new face walk in and buy something?',
            'body' => <<<'BODY'
Every shop has the same quiet stretch. The chairs are empty on a Tuesday afternoon. The cafe is calm after the morning rush. And the marketing bill still arrives, for the full amount, as if the month had been busy.

That is the part of most marketing that never felt fair to us. You pay first. You hope later. If nobody new walks in, the money is still gone.

So here is the test we think every marketing cost should have to pass. One question: did a new face walk in and buy something? If the answer is no, the cost should be no as well.

Monthly tools fail that test in a quiet month. The fee is the same in your best month and your worst month. Deal sites fail it in a different way: a stranger comes in once for a deep discount, the site takes its cut of a ticket you already cut, and you may never see that person again.

Easy Refer was built to pass the test. The whole idea fits in one sentence: I bring you customers in. Everything else is just how it happens.

Your customers do the bringing. A regular scans a code on your counter, confirms their phone, and sends their link to a friend. When that friend walks in and uses their reward, that is a visit that counts. That visit is the only thing you ever pay for.

The price is plain. There is no monthly fee and no setup fee. Your first 5 visits that count are free. After that, a visit costs $2, charged once a month. You never pay more than $99 in a month in visit charges, no matter how many friends walk in. If nobody walks in, you pay nothing.

You also choose what the visit costs you at the counter, because you choose the reward. A free coffee. A free beard trim with a cut. A set amount off a first visit. The friend gets their reward when they visit. Your regular gets theirs after a short wait, and the date is written on the reward itself, so nobody has to guess.

We will be honest about the limit, because every tool has one. A code on a counter brings nobody in by itself. Customers have to see it, and they have to like you enough to send the link. What the program does is turn the good word your customers already say about you into a visit you can count, instead of a compliment that drifts away.

Run the test on anything you pay for now. Ads. A monthly app. A deal site. Ask the one question about last month: how many new faces walked in and bought something, and what did each one cost you? If the answer is a shrug, the cost failed the test.

If you want to see how this feels from the customer side, try the demo on our home page. You play the customer: scan, share, claim. It takes about a minute, and it is the whole program. If it makes sense in your hand, starting is free, and nothing is charged until after your first 5 visits that count.
BODY,
        ],
        [
            'slug' => 'how-a-referral-program-works',
            'title' => 'How a referral program works in a real shop, step by step',
            'excerpt' => 'No app to install and no cards to stamp. One code on your counter does the whole job. Here is what your customer sees, and what you see.',
            'body' => <<<'BODY'
Most owners we talk to picture the same thing when they hear "referral program": software to learn, cards to keep track of, one more password, one more thing for the staff to remember at a busy counter.

So let us walk through the real thing, in a real shop, in the order it actually happens. There are five steps. Your part is the first one and the last one. The middle belongs to your customers.

Step 1: you put your code where customers can see it. When you sign up, your shop gets its own QR code. You print it and place it: a poster by the register, a small card on the counter, a card that goes out with a receipt. That is the whole setup at the shop. There is nothing to install and nothing to plug in.

Step 2: a customer scans the code and joins. The code opens a short page with your shop's name and the reward you picked. The customer types their name and mobile number and confirms the number with a code we text them. That confirmation is what keeps the program honest: a visit counts only when a real phone stands behind it. It takes under a minute, and there is no app to install.

Step 3: your customer sends their link to a friend. Joining gives the customer their own link and their own code to show in person. They send it the way they send anything: a text, a chat, however they already talk to their friends. You write nothing and send nothing. Your job ended at making them happy enough to share.

Step 4: the friend comes in and uses their reward. The friend opens the link, confirms their phone the same way, and gets their reward ready to use. At your counter, your staff scan the friend's reward code on their own phone and press one button, Mark as Redeemed. No PIN to share, no password at the register, no paper list. The moment that button is pressed, the visit counts.

Step 5: you see it, and the rewards land where they should. The friend's reward is given at the visit, on the spot. Your regular's reward comes after a short wait, and the exact date is written on the reward itself, so there is never an argument about when. Your dashboard shows the referral, who sent the friend, and what the visit was. That is also where your bill comes from: your first 5 visits that count are free, then each visit is $2, charged once a month, and never more than $99 in a month in visit charges.

Notice what is not on the list. Nobody downloads an app. Nobody carries a card that gets lost. Your staff learn one button. You never hand out a reward to someone who did not walk in, because the reward only completes at your counter, in person.

The reward itself is your choice, and it should fit your ticket. A cafe can give a free regular coffee: it costs the shop little in product and reads at full menu price to the customer. A barber can give $10 off a first cut, the pair most barbers start with, with $10 off the regular's next cut on the other side. A salon can give $15 off a color service, a small slice of a full service ticket. These are the starter rewards the product suggests at signup, and you can edit them any time.

Setup, in plain words: sign up, pick your reward, print your code, put a card on file when you are ready. Minutes, not an afternoon. And if a quiet month follows, the program costs you nothing for that month, because you only pay when a friend walks in and the visit counts.

The fastest way to understand it is to be the customer once. The demo on our home page runs this exact flow on your own phone, start to finish, in about a minute. After that, the five steps above will not feel like software. They will feel like your counter, on a good day.
BODY,
        ],
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $definition) {
            $post = Post::query()->firstOrNew(['slug' => $definition['slug']]);

            $post->fill([
                'title' => $definition['title'],
                'excerpt' => $definition['excerpt'],
                'body' => $definition['body'],
                'published_at' => $post->published_at ?? now(),
            ]);
            $post->save();
        }
    }
}
