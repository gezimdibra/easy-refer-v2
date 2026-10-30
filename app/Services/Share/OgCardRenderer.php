<?php

namespace App\Services\Share;

use App\Models\Business;
use Illuminate\Support\Facades\File;

/**
 * RS-N1 (Train B, Amendment 39): the per-business Open Graph
 * card for friend share links, Variant A "Ink field" from the
 * design pass (ink ground, the reward at display size in white,
 * the business line in amber under it, the Concept D wordmark
 * and sweep check at the top, an amber foot bar).
 *
 * The card is PER BUSINESS, never per link: it is composed only
 * from the business's own stored name and its friend reward
 * headline (Business::friendRewardHeadline(), the same composer
 * the landing H1 and the posters use), so if the landing page
 * does not say it, the image cannot say it. No sender name, no
 * share token, no date: a share link has no expiry, and the
 * sender's first name travels in og:title (HTML, per link)
 * instead. Rendering is cached on disk under a content version
 * hash; a settings edit changes the hash and produces exactly
 * one new render, and the route re-checks the business's live
 * state on every fetch, so a card minted while active is never
 * served after the business pauses or closes.
 *
 * The generic brand card asserts nothing about any business: it
 * is served, in place of a reward card, whenever the business
 * behind a presented slug and version is not live.
 */
class OgCardRenderer
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /** Ink and Amber tokens (resources/css/app.css). */
    private const INK = [0x16, 0x13, 0x0E];

    private const AMBER = [0xF5, 0xA3, 0x00];

    private const CREAM = [0xFA, 0xF6, 0xEF];

    private const MUTED = [0xE7, 0xDC, 0xC8];

    private const WHITE = [0xFF, 0xFF, 0xFF];

    /** Brand version: bump when the card's own layout changes. */
    private const BRAND_VERSION = 'ink-a1';

    /**
     * The content version for a business's card: a short hash of
     * everything the card renders, plus the brand version. The
     * hash is the only key the public image URL carries besides
     * the business's public slug (already printed on its
     * posters); it contains no token and no customer data.
     */
    public function versionFor(Business $business): string
    {
        return substr(hash('sha256', implode('|', [
            $business->id,
            (string) $business->name,
            (string) $business->friend_reward_text,
            self::BRAND_VERSION,
        ])), 0, 12);
    }

    /**
     * The og: payload for a friend landing, or null when there
     * is nothing honest to preview. The controller calls this
     * only on the live path; the renderer still refuses a
     * non-live business so no other caller can mint a reward
     * preview for one.
     *
     * @return array{title: string, description: string, image: string, alt: string}|null
     */
    public function metaFor(Business $business, string $referrerFirstName): ?array
    {
        if (! $business->flowIsActive()) {
            return null;
        }

        $headline = $business->friendRewardHeadline();
        $slug = $business->ensureJoinSlug();

        return [
            'title' => $referrerFirstName.' sent you a reward at '.$business->name,
            'description' => $headline !== null
                ? 'Claim '.rtrim($headline, '.').' at '.$business->name.'. Claim it here, then show it when you visit.'
                : 'Claim your reward at '.$business->name.'. Claim it here, then show it when you visit.',
            'image' => route('refer.og.card', ['slug' => $slug, 'version' => $this->versionFor($business)]),
            'alt' => $headline !== null
                ? $business->name.': '.$headline
                : $business->name.': A reward from a friend',
        ];
    }

    /** PNG bytes for the business's reward card (disk-cached). */
    public function rewardCardPng(Business $business): string
    {
        $file = $this->cachePath('biz'.$business->id.'-'.$this->versionFor($business).'.png');

        if (File::exists($file)) {
            return (string) File::get($file);
        }

        $headline = $business->friendRewardHeadline();
        $png = $this->render(
            rewardLines: self::fittedRewardLines($headline ?? 'A reward from a friend'),
            businessName: $business->name,
        );

        $this->store($file, $png);
        // One live card per business: retire superseded versions.
        foreach (File::glob($this->cacheDir().'/biz'.$business->id.'-*.png') as $stale) {
            if ($stale !== $file) {
                File::delete($stale);
            }
        }

        return $png;
    }

    /** PNG bytes for the generic brand card (disk-cached). */
    public function genericCardPng(): string
    {
        $file = $this->cachePath('generic-'.self::BRAND_VERSION.'.png');

        if (File::exists($file)) {
            return (string) File::get($file);
        }

        $png = $this->render(
            rewardLines: self::fittedRewardLines('Get Your Customers to Refer Their Friends'),
            businessName: null,
        );

        $this->store($file, $png);

        return $png;
    }

    /**
     * Fit a reward headline to the card: the largest type tier
     * whose wrap fits three lines, else the smallest tier with
     * the text truncated to a prefix plus an ellipsis.
     * Truncation may shorten the owner's words; it never
     * completes, extends, or re-punctuates them into a different
     * promise.
     *
     * @return list<string>
     */
    public static function fittedRewardLines(string $text): array
    {
        $fonts = self::fonts();
        $maxWidth = self::WIDTH - 144;

        foreach ([96, 80, 66, 54, 44] as $size) {
            $lines = self::wrapLines($text, $fonts['bold'], $size, $maxWidth);
            // At most three lines, and the stack must clear the
            // business line: first baseline 306, step size*1.22,
            // last baseline at or above 470.
            if (count($lines) <= 3 && (count($lines) - 1) * $size * 1.22 <= 164) {
                return $lines;
            }
        }

        $candidate = $text;
        while ($candidate !== '' && count(self::wrapLines($candidate."\u{2026}", $fonts['bold'], 44, $maxWidth)) > 3) {
            $next = preg_replace('/\s*\S+$/u', '', $candidate);
            if ($next === null || $next === '' || $next === $candidate) {
                // A single overlong word (or the last word):
                // trim one character instead of the whole word.
                $next = mb_substr($candidate, 0, -1);
            }
            $candidate = $next;
        }

        return self::wrapLines($candidate."\u{2026}", $fonts['bold'], 44, $maxWidth);
    }

    /**
     * Word-wrap for GD text, hard-breaking any single word wider
     * than the measure so hostile-length input stays in canvas.
     *
     * @return list<string>
     */
    public static function wrapLines(string $text, ?string $font, float $size, float $maxWidth): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = '';

        $fits = function (string $value) use ($font, $size, $maxWidth): bool {
            if ($font === null || ! is_file($font)) {
                // No TTF in this environment: estimate the width
                // so wrapping still bounds the text.
                return mb_strlen($value) * $size * 0.55 <= $maxWidth;
            }
            $box = imagettfbbox($size, 0, $font, $value);

            return $box !== false && ($box[2] - $box[0]) <= $maxWidth;
        };

        foreach ($words as $word) {
            while (! $fits($word)) {
                // Hard-break an overlong word at the widest
                // prefix that fits.
                $cut = mb_strlen($word);
                while ($cut > 1 && ! $fits(mb_substr($word, 0, $cut))) {
                    $cut--;
                }
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }

            $trial = $current === '' ? $word : $current.' '.$word;
            if ($fits($trial)) {
                $current = $trial;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * Font pair (bold, regular) for GD rendering. The DejaVu
     * pair ships IN the tree at resources/fonts/og (binary
     * files, so they are standing GitHub-snapshot exclusions
     * and travel in deploy kit tarballs instead; see
     * code.muse-github-snapshot-sync.json), which makes every
     * release self-contained: the staging host has no TTF in
     * the system candidates below, and r42's Gate 1 hard-failed
     * on exactly that. Discovery order: an env override
     * directory first (ops can point at another pair without a
     * code change), then the in-tree pair, then the system
     * candidates. The fallback, if no TTF exists at all, is
     * GD's built-in font: degraded type, never a failed render.
     *
     * @return array{bold: ?string, regular: ?string}
     */
    public static function fonts(): array
    {
        $dirs = array_filter([
            getenv('OG_CARD_FONT_DIR') ?: null,
            base_path('resources/fonts/og'),
            '/usr/share/fonts/truetype/dejavu',
            '/usr/share/fonts/truetype/liberation',
            '/usr/share/fonts/truetype/noto',
        ]);
        $pairs = [
            ['DejaVuSans-Bold.ttf', 'DejaVuSans.ttf'],
            ['LiberationSans-Bold.ttf', 'LiberationSans-Regular.ttf'],
            ['NotoSans-Bold.ttf', 'NotoSans-Regular.ttf'],
        ];

        foreach ($dirs as $dir) {
            foreach ($pairs as [$bold, $regular]) {
                if (is_file($dir.'/'.$bold) && is_file($dir.'/'.$regular)) {
                    return ['bold' => $dir.'/'.$bold, 'regular' => $dir.'/'.$regular];
                }
            }
        }

        return ['bold' => null, 'regular' => null];
    }

    /**
     * @param  list<string>  $rewardLines
     */
    private function render(array $rewardLines, ?string $businessName): string
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $ink = imagecolorallocate($image, ...self::INK);
        $amber = imagecolorallocate($image, ...self::AMBER);
        $cream = imagecolorallocate($image, ...self::CREAM);
        $muted = imagecolorallocate($image, ...self::MUTED);
        $white = imagecolorallocate($image, ...self::WHITE);
        imagefill($image, 0, 0, $ink);

        $fonts = self::fonts();
        $x = 72;

        // Wordmark and the sweep check (drawn, so it renders the
        // same in every font environment).
        $this->text($image, $fonts['bold'], 37, $x, 108, $cream, 'Easy Refer');
        $wordmarkWidth = $fonts['bold'] !== null ? (imagettfbbox(37, 0, $fonts['bold'], 'Easy Refer')[2] ?? 210) : 210;
        $checkX = $x + $wordmarkWidth + 18;
        imagesetthickness($image, 8);
        imageline($image, $checkX, 84, $checkX + 15, 100, $amber);
        imageline($image, $checkX + 15, 100, $checkX + 42, 66, $amber);

        // The sender line is generic in the image by design: the
        // image is cached per business, and the sender's first
        // name travels in og:title only.
        $this->text($image, $fonts['regular'], 36, $x, 192, $muted, $businessName === null ? 'Referral marketing for local businesses' : 'A friend sent you a reward');

        // The reward, at the largest tier that fit it.
        $size = $this->rewardSize($rewardLines);
        $baseline = 306;
        foreach ($rewardLines as $line) {
            $this->text($image, $fonts['bold'], $size, $x, $baseline, $white, $line);
            $baseline += (int) round($size * 1.22);
        }

        if ($businessName !== null) {
            $this->text($image, $fonts['bold'], 46, $x, 516, $amber, 'at '.$this->fitOneLine($businessName, $fonts['bold'], 46));
            $this->text($image, $fonts['regular'], 30, $x, 570, $muted, 'Claim it, then show it when you visit.');
        }

        imagefilledrectangle($image, 0, self::HEIGHT - 24, self::WIDTH, self::HEIGHT, $amber);

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** @param list<string> $lines */
    private function rewardSize(array $lines): int
    {
        // Reverse the tier search fittedRewardLines performed:
        // the tier is recoverable from the line count only
        // approximately, so the render re-derives it from the
        // longest line's measured width at each tier.
        $fonts = self::fonts();
        if ($fonts['bold'] === null) {
            return 44;
        }
        foreach ([96, 80, 66, 54, 44] as $size) {
            $ok = (count($lines) - 1) * $size * 1.22 <= 164;
            foreach ($lines as $line) {
                $box = imagettfbbox($size, 0, $fonts['bold'], $line);
                if ($box === false || ($box[2] - $box[0]) > self::WIDTH - 144) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $size;
            }
        }

        return 44;
    }

    private function fitOneLine(string $text, ?string $font, float $size): string
    {
        if ($font === null) {
            return $text;
        }
        $maxWidth = self::WIDTH - 144;
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box !== false && ($box[2] - $box[0]) <= $maxWidth) {
            return $text;
        }
        while ($text !== '') {
            $text = mb_substr($text, 0, -1);
            $box = imagettfbbox($size, 0, $font, $text."\u{2026}");
            if ($box !== false && ($box[2] - $box[0]) <= $maxWidth) {
                return $text."\u{2026}";
            }
        }

        return $text;
    }

    private function text(\GdImage $image, ?string $font, float $size, int $x, int $y, int $color, string $text): void
    {
        if ($font !== null) {
            imagettftext($image, $size, 0, $x, $y, $color, $font, $text);
        } else {
            imagestring($image, 5, $x, $y - 16, $text, $color);
        }
    }

    private function cacheDir(): string
    {
        return storage_path('app/og-cards');
    }

    private function cachePath(string $name): string
    {
        return $this->cacheDir().'/'.$name;
    }

    private function store(string $file, string $png): void
    {
        File::ensureDirectoryExists($this->cacheDir());
        File::put($file, $png);
    }
}
