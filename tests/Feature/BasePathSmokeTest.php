<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Spec Section 11: APP_URL is the single config point for the mount.
 * Every expectation here is derived from the configured mount, so
 * the same assertions run at the staging mount under the default
 * test environment and at the root mount under a root-flavored one
 * (finding FB-06: the earlier version hardcoded the staging values,
 * which made its root-mount runs fail on tautologies instead of
 * evidence). The dual-mount proof is the two full-suite runs
 * recorded in the Phase 1 fix report.
 */
class BasePathSmokeTest extends TestCase
{
    /**
     * The mount path the current configuration declares: the path
     * component of APP_URL, or "/" at the root mount.
     */
    private function configuredMountPath(): string
    {
        $path = parse_url((string) config('app.url'), PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public function test_app_url_is_the_single_config_point_for_generated_urls(): void
    {
        $base = rtrim((string) config('app.url'), '/');

        $this->assertSame($base.'/health', route('health'));
        $this->assertSame($base.'/auth/login', url('auth/login'));
    }

    public function test_health_endpoint_answers_under_the_configured_mount(): void
    {
        $this->get(route('health'))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'service' => 'easy-refer-v2']);
    }

    public function test_home_page_answers_at_the_mount_root(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(config('app.name'));
    }

    public function test_session_cookie_is_scoped_to_the_configured_mount(): void
    {
        $this->assertSame('er2_session', config('session.cookie'));
        $this->assertSame($this->configuredMountPath(), config('session.path'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_the_same_build_serves_from_the_root_mount(): void
    {
        // The production cutover flips .env before boot (spec Section
        // 11.2); by request time every consumer reads config, so setting
        // the config values the flipped env would produce is the same
        // runtime state. This drives that state on a fresh application.
        $this->refreshApplication();
        config(['app.url' => 'http://localhost', 'session.path' => '/']);
        URL::forceRootUrl('http://localhost');

        $this->assertSame('http://localhost', config('app.url'));
        $this->assertSame('http://localhost/health', route('health'));
        $this->assertSame('/', config('session.path'));

        $this->get(route('health'))->assertOk();
        $this->get('/')->assertOk()->assertSee(config('app.name'));
    }
}
