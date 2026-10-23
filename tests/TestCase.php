<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Uri;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Views reference the Vite build through @vite; the suite runs
        // without a compiled asset manifest (assets are built at
        // deploy time), so the Vite integration is disabled here the
        // way the framework intends for tests. Asset compilation
        // itself is proven by the build step, not by page tests.
        $this->withoutVite();
    }

    /**
     * Dispatch a test request the way the web server does when the app is
     * mounted under a subpath (spec Section 11): the front controller
     * lives inside the mount folder, so SCRIPT_NAME carries the mount and
     * the router sees only the path below it. When APP_URL has no path
     * (root mount) requests pass through unchanged.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $basePath = parse_url((string) config('app.url'), PHP_URL_PATH) ?: '';

        if ($basePath !== '' && $basePath !== '/') {
            $server['SCRIPT_NAME'] ??= rtrim($basePath, '/').'/index.php';
            $server['SCRIPT_FILENAME'] ??= '/var/www'.$server['SCRIPT_NAME'];
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Carry a response's cookies into later requests exactly as a
     * browser would: the values are the encrypted Set-Cookie values,
     * presented back unmodified. The test client does not persist
     * cookies between requests on its own, and session behavior (the
     * cookie pinning the session id) is only real when they are carried.
     */
    protected function carryCookiesFrom(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue());
        }
    }

    /**
     * Symfony rebuilds REQUEST_URI from the URL path, and the framework
     * trims trailing slashes, which would turn the bare mount root into
     * a path the front controller math cannot resolve. Restore the slash
     * for exactly that one URL so the root of the mount is requestable,
     * as a browser would request it.
     */
    protected function prepareUrlForRequest($uri)
    {
        $uri = $uri instanceof Uri ? $uri->value() : (string) $uri;

        if (str_starts_with($uri, '/')) {
            $uri = substr($uri, 1);
        }

        $prepared = trim(url($uri), '/');

        $basePath = parse_url((string) config('app.url'), PHP_URL_PATH) ?: '';

        if ($basePath !== '' && $basePath !== '/' && parse_url($prepared, PHP_URL_PATH) === $basePath) {
            $prepared .= '/';
        }

        return $prepared;
    }
}
