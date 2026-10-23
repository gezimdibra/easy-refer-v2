<?php

namespace App\Support;

/**
 * The Google review link rule (spec Section 5.5): the URL an owner
 * pastes is validated for shape before it is ever rendered on a
 * receipt. HTTPS only, and the host must be one of the shapes
 * Google issues review links on: the g.page short domain, the
 * Maps short domain, or a Google search/maps host. Anything else
 * is rejected with the inventory's plain error (SET-05), because
 * this link is shown to every customer who redeems or claims.
 */
final class GoogleReviewUrl
{
    /**
     * @var list<string>
     */
    private const ALLOWED_HOSTS = [
        'g.page',
        'maps.app.goo.gl',
        'www.google.com',
        'search.google.com',
    ];

    public static function isValid(string $url): bool
    {
        return self::canonicalize($url) !== null;
    }

    /**
     * The canonical form to store, or null when the URL is not an
     * allowlisted Google review link. The stored value is rebuilt
     * from the parsed parts, never the raw input, because PHP's
     * parser and the browser's WHATWG parser disagree on inputs
     * like a backslash before the host ("https://evil.com\@g.page/x"
     * reads as host g.page to PHP and as host evil.com to every
     * browser; security verifier F-01). Backslashes, userinfo,
     * and whitespace or control characters are rejected outright,
     * so what is stored is exactly what a browser will open.
     */
    public static function canonicalize(string $url): ?string
    {
        if (strlen($url) > 2048 || $url !== trim($url)) {
            return null;
        }

        if (str_contains($url, '\\') || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            return null;
        }

        $canonical = 'https://'.$host.($parts['path'] ?? '');

        if (isset($parts['query'])) {
            $canonical .= '?'.$parts['query'];
        }

        return $canonical;
    }
}
