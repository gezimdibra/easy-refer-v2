<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Portal refresh (P1: U1 + U3). The portal (app, admin, and auth
 * layouts) carries a scoped theme, .portal-theme, that re-declares
 * the color tokens per scheme: L2 Cool Slate in light, D2
 * Midnight Slate in dark (the owner-notes gallery pair picked
 * 2026-10-27 after the owner said "Decide for me"; values are
 * the gallery capture source's token tables verbatim). The L2
 * block also re-points the action/link/focus roles at the slate
 * ink (the gallery direction: the roles follow the ink); the
 * dark roles keep the global amber values. The GLOBAL table in
 * @theme and the global dark block do not move: the landing and
 * the customer flow keep asserting the current values (the
 * DM-05 split, in-suite form), so this pin holds both tables at
 * once and fails if either drifts.
 *
 * Absence half (the standing verify-absence rule): no portal
 * block may carry the pre-refresh portal grounds (#faf6ef
 * light, #161310 dark), the old dark card (#221d17), or the
 * retired L1/D1 grounds and card (#fafaf8, #151412, #1e1d1b),
 * and the flow and marketing layouts must NOT carry the portal
 * scope.
 */
class PortalRefreshThemeTest extends TestCase
{
    /** @var array<string, string> */
    private const PORTAL_LIGHT = [
        'surface-1' => '#f4f6f8',
        'surface-0' => '#ffffff',
        'surface-2' => '#ebeef2',
        'surface-3' => '#dce2e9',
        'line' => '#dde3ea',
        'line-strong' => '#7a8694',
        'control' => '#5b6876',
        'text-1' => '#131a22',
        'text-2' => '#3d4a5a',
        'text-3' => '#5e6d7e',
        'text-disabled' => '#9aa6b2',
        'brand-50' => '#fffaf1',
        'brand-100' => '#fdebc8',
        'action' => '#131a22',
        'action-hover' => '#0b1118',
        'action-pressed' => '#05080c',
        'link' => '#0b1118',
        'focus' => '#131a22',
    ];

    /** @var array<string, string> */
    private const PORTAL_DARK = [
        'surface-1' => '#0e1116',
        'surface-0' => '#161b22',
        'surface-2' => '#1e2630',
        'surface-3' => '#2a3442',
        'line' => '#262f3c',
        'line-strong' => '#6b7a8d',
        'control' => '#8e9eb3',
        'text-1' => '#f2f5f8',
        'text-2' => '#c2ceda',
        'text-3' => '#94a6b8',
        'text-disabled' => '#5d6b7d',
        'brand-50' => '#2e2413',
        'brand-100' => '#4d3a10',
        'success-fill' => '#12261e',
        'warning-fill' => '#2b2110',
        'error-fill' => '#2e1611',
        'info-fill' => '#162a38',
        // The dark role re-declarations: without them the
        // unconditional L2 roles win the cascade in dark.
        'action' => '#ffb224',
        'action-hover' => '#f5a300',
        'action-pressed' => '#e09500',
        'link' => '#ffb224',
        'focus' => '#ffb224',
    ];

    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    /**
     * The variable declarations of the .portal-theme block that
     * follows the given marker comment in app.css.
     *
     * @return array<string, string>
     */
    private function portalVars(string $css, string $marker): array
    {
        $markerPos = strpos($css, $marker);
        $this->assertNotFalse($markerPos, "portal marker {$marker} missing from app.css");

        $blockStart = strpos($css, '.portal-theme {', (int) $markerPos);
        $this->assertNotFalse($blockStart, "portal block after {$marker} missing from app.css");

        $blockEnd = strpos($css, '}', (int) $blockStart);
        $block = substr($css, (int) $blockStart, (int) $blockEnd - (int) $blockStart);

        $vars = [];
        preg_match_all('/--color-([a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $block, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $vars[$match[1]] = strtolower($match[2]);
        }

        return $vars;
    }

    private function portalBlockText(string $css, string $marker): string
    {
        $markerPos = strpos($css, $marker);
        $this->assertNotFalse($markerPos, "portal marker {$marker} missing from app.css");
        $blockStart = strpos($css, '.portal-theme {', (int) $markerPos);
        $blockEnd = strpos($css, '}', (int) $blockStart);

        return substr($css, (int) $blockStart, (int) $blockEnd - (int) $blockStart);
    }

    public function test_portal_light_block_declares_the_l2_table_exactly(): void
    {
        $vars = $this->portalVars($this->css(), 'Portal refresh, L2');

        foreach (self::PORTAL_LIGHT as $name => $value) {
            $this->assertSame($value, $vars[$name] ?? null, "portal light --color-{$name}");
        }
    }

    public function test_portal_dark_block_declares_the_d2_table_exactly(): void
    {
        $vars = $this->portalVars($this->css(), 'Portal refresh, D2');

        foreach (self::PORTAL_DARK as $name => $value) {
            $this->assertSame($value, $vars[$name] ?? null, "portal dark --color-{$name}");
        }
    }

    public function test_portal_blocks_carry_none_of_the_old_portal_values(): void
    {
        $css = $this->css();

        foreach (['Portal refresh, L2', 'Portal refresh, D2'] as $marker) {
            $block = strtolower($this->portalBlockText($css, $marker));
            foreach (['#faf6ef', '#161310', '#221d17', '#fafaf8', '#151412', '#1e1d1b'] as $old) {
                $this->assertStringNotContainsString($old, $block, "portal block {$marker} still carries {$old}");
            }
        }
    }

    public function test_the_global_table_is_untouched_by_the_portal_scope(): void
    {
        $css = $this->css();

        // Light globals: the first declarations in the file are
        // the @theme table (the portal blocks come later).
        foreach (['surface-1' => '#faf6ef', 'surface-2' => '#efe4cf', 'line' => '#dccfb2'] as $name => $value) {
            $pos = strpos($css, '--color-'.$name.':');
            $this->assertNotFalse($pos, "global light token {$name} missing");
            $found = strtolower(trim(substr($css, (int) $pos + strlen('--color-'.$name.':'), 8), " \t;"));
            $this->assertSame($value, $found, "global light --color-{$name} moved");
        }

        // Dark globals: the first dark media block is the global
        // one; its surface tokens keep the pre-refresh values.
        $darkStart = strpos($css, '@media (prefers-color-scheme: dark)');
        $this->assertNotFalse($darkStart, 'global dark block missing');
        $darkBlock = substr($css, (int) $darkStart, 3000);
        foreach (['surface-1' => '#161310', 'surface-0' => '#221d17'] as $name => $value) {
            $pos = strpos($darkBlock, '--color-'.$name.':');
            $this->assertNotFalse($pos, "global dark token {$name} missing");
            $found = strtolower(trim(substr($darkBlock, (int) $pos + strlen('--color-'.$name.':'), 8), " \t;"));
            $this->assertSame($value, $found, "global dark --color-{$name} moved");
        }
    }

    public function test_only_the_portal_layouts_carry_the_scope(): void
    {
        foreach (['app', 'admin', 'auth'] as $layout) {
            $source = (string) file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));
            $this->assertStringContainsString('portal-theme', $source, "{$layout} layout lacks the portal scope");
        }

        foreach (['flow', 'marketing'] as $layout) {
            $source = (string) file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));
            $this->assertStringNotContainsString('portal-theme', $source, "{$layout} layout must not carry the portal scope");
        }
    }

    public function test_the_portal_dark_select_chevron_is_restroked_to_the_d2_text_3(): void
    {
        $css = $this->css();
        $markerPos = strpos($css, 'Portal refresh, D2');
        $this->assertNotFalse($markerPos, 'portal dark marker missing');

        // The chevron rule rides in the same dark media block,
        // just after the variable block.
        $window = substr($css, (int) $markerPos, 4000);
        $this->assertStringContainsString("%2394a6b8", $window, 'portal dark chevron stroke is not the D2 text-3');
        $this->assertStringContainsString('.portal-theme .form-select', $window, 'portal dark chevron is not scoped to the portal');
    }
}
