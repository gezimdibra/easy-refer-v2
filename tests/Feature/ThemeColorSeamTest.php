<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * SEAM-01 (owner-found on his iPhone, 2026-10-25): the strip
 * behind the phone's status bar is painted from the page's
 * theme-color meta, and it must be the SAME color as the
 * band at the very top of the page — one designed surface,
 * no seam. The rule, per layout: each scheme's theme-color
 * equals the background token of the element at the top of
 * that layout (the header band where the layout has one,
 * the body's ground where it does not), and the <html>
 * ground carries the same token so overscroll shows no
 * third color. A light-locked layout ships no dark-scheme
 * value at all.
 *
 * The token VALUES live in resources/css/app.css (one
 * source); this pin parses them from there and fails the
 * moment a blade's meta drifts from its header token — so
 * a future palette change moves both or fails here, by
 * construction.
 *
 * Portal refresh (P1, U2-revalue): the portal layouts (app,
 * admin, auth) render under the .portal-theme scope, so
 * their top token is the PORTAL surface-0 per scheme (dark
 * #161b22 under D2), parsed from the portal blocks. The
 * flow and marketing layouts keep the global table's
 * surface tokens.
 */
class ThemeColorSeamTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    private function tokens(): array
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $light = [];
        foreach (['surface-0', 'surface-1'] as $name) {
            $pos = strpos($css, '--color-'.$name.':');
            $this->assertNotFalse($pos, "light token {$name} missing from app.css");
            $light[$name] = strtolower(trim(substr($css, $pos + strlen('--color-'.$name.':'), 8), " \t;"));
        }

        $darkBlockStart = strpos($css, '@media (prefers-color-scheme: dark)');
        $this->assertNotFalse($darkBlockStart, 'dark media block missing from app.css');
        $darkBlock = substr($css, $darkBlockStart, 3000);

        $dark = [];
        foreach (['surface-0', 'surface-1'] as $name) {
            $pos = strpos($darkBlock, '--color-'.$name.':');
            $this->assertNotFalse($pos, "dark token {$name} missing from the dark :root block");
            $dark[$name] = strtolower(trim(substr($darkBlock, $pos + strlen('--color-'.$name.':'), 8), " \t;"));
        }

        return ['light' => $light, 'dark' => $dark];
    }

    /**
     * The portal scope's surface-0 per scheme, parsed from the
     * .portal-theme blocks in app.css (markers as in
     * PortalRefreshThemeTest).
     *
     * @return array{light: string, dark: string}
     */
    private function portalSurface0(): array
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $values = [];
        foreach (['light' => 'Portal refresh, L2', 'dark' => 'Portal refresh, D2'] as $scheme => $marker) {
            $markerPos = strpos($css, $marker);
            $this->assertNotFalse($markerPos, "portal marker {$marker} missing from app.css");
            $blockStart = strpos($css, '.portal-theme {', (int) $markerPos);
            $this->assertNotFalse($blockStart, "portal block after {$marker} missing from app.css");
            $pos = strpos($css, '--color-surface-0:', (int) $blockStart);
            $this->assertNotFalse($pos, "portal {$scheme} surface-0 missing from app.css");
            $values[$scheme] = strtolower(trim(substr($css, (int) $pos + strlen('--color-surface-0:'), 8), " \t;"));
        }

        return $values;
    }

    /**
     * @return array<string, string> scheme => content value
     */
    private function metas(string $bladeSource): array
    {
        preg_match_all(
            '/name="theme-color"\s+media="\(prefers-color-scheme:\s*(light|dark)\)"\s+content="([^"]+)"/',
            $bladeSource,
            $matches,
            PREG_SET_ORDER,
        );

        $metas = [];
        foreach ($matches as $match) {
            $metas[$match[1]] = strtolower($match[2]);
        }

        return $metas;
    }

    private function layoutSource(string $layout): string
    {
        return (string) file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));
    }

    public function test_every_layouts_theme_color_equals_its_top_band_token(): void
    {
        $tokens = $this->tokens();
        $portal = $this->portalSurface0();

        // app + admin: a surface-0 header band over a
        // surface-1 ground. auth: no band; the page top IS
        // the surface-0 body. All three render under the
        // portal scope, so they take the PORTAL surface-0.
        foreach (['app', 'admin', 'auth'] as $layout) {
            $metas = $this->metas($this->layoutSource($layout));
            $this->assertSame($portal['light'], $metas['light'] ?? null, "{$layout} light theme-color");
            $this->assertSame($portal['dark'], $metas['dark'] ?? null, "{$layout} dark theme-color");
        }

        // flow: no band; the top of the page is the surface-1
        // body itself, so the meta follows surface-1.
        $metas = $this->metas($this->layoutSource('flow'));
        $this->assertSame($tokens['light']['surface-1'], $metas['light'] ?? null, 'flow light theme-color');
        $this->assertSame($tokens['dark']['surface-1'], $metas['dark'] ?? null, 'flow dark theme-color');

        // marketing: body and header are both surface-0.
        $metas = $this->metas($this->layoutSource('marketing'));
        $this->assertSame($tokens['light']['surface-0'], $metas['light'] ?? null, 'marketing light theme-color');
        $this->assertSame($tokens['dark']['surface-0'], $metas['dark'] ?? null, 'marketing dark theme-color');
    }

    public function test_the_html_ground_carries_the_same_token_as_the_top_band(): void
    {
        foreach (['app', 'admin', 'auth', 'marketing'] as $layout) {
            $this->assertStringContainsString('bg-surface-0', $this->htmlTag($this->layoutSource($layout)), "{$layout} html ground");
        }

        $this->assertStringContainsString('bg-surface-1', $this->htmlTag($this->layoutSource('flow')), 'flow html ground');
    }

    private function htmlTag(string $bladeSource): string
    {
        $start = strpos($bladeSource, '<html');

        // A fixed window, not a search for the closing bracket:
        // the lang attribute's Blade expression contains '->'.
        return $start === false ? '' : substr($bladeSource, $start, 220);
    }

    public function test_the_light_locked_landing_ships_no_dark_theme_color(): void
    {
        $tokens = $this->tokens();

        // Lowercased for the value check: the blade writes the
        // hex in display case, the token source in CSS case.
        $html = strtolower($this->get('/')->assertOk()->getContent());

        $this->assertSame(1, substr_count($html, 'name="theme-color"'), 'the locked landing carries exactly one theme-color');
        $this->assertStringContainsString('content="'.$tokens['light']['surface-0'].'"', $html);
    }

    public function test_rendered_owner_and_auth_pages_carry_the_band_color(): void
    {
        // Both rendered pages are portal surfaces (auth login,
        // owner dashboard), so they carry the PORTAL top token.
        $portal = $this->portalSurface0();

        // The guest page first: once signed in, /login is a
        // redirect, not a page.
        $login = strtolower($this->get(route('login'))->assertOk()->getContent());
        $this->assertStringContainsString('media="(prefers-color-scheme: light)" content="'.$portal['light'].'"', $login);
        $this->assertStringContainsString('media="(prefers-color-scheme: dark)" content="'.$portal['dark'].'"', $login);

        $business = World::business();
        $owner = World::owner($business);

        $dashboard = strtolower($this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent());
        $this->assertStringContainsString('media="(prefers-color-scheme: light)" content="'.$portal['light'].'"', $dashboard);
        $this->assertStringContainsString('media="(prefers-color-scheme: dark)" content="'.$portal['dark'].'"', $dashboard);
    }
}
