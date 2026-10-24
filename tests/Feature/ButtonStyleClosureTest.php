<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F-01 regression guard (Sprint 2 fix wave): the .btn-brand class
 * shipped on ten primary actions while being defined nowhere, so
 * every one of them rendered transparent and stateless. This test
 * closes the class-to-style loop permanently: every btn-* class
 * used in any Blade view must be defined in the shipped CSS, and
 * every color-bearing variant must carry a background, a text
 * color, and hover, active, and disabled states in its rule. The
 * focus state is the global :focus-visible ring (Section 6.6),
 * asserted here so it cannot silently disappear either.
 */
class ButtonStyleClosureTest extends TestCase
{
    /** @return list<string> */
    private function usedButtonClasses(): array
    {
        $used = [];
        $views = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($views as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all('/btn-[a-z][a-z-]*/', (string) file_get_contents($file->getPathname()), $matches);
            $used = array_merge($used, $matches[0]);
        }

        return array_values(array_unique($used));
    }

    private function cssRule(string $css, string $class): ?string
    {
        if (preg_match('/\.'.preg_quote($class, '/').'\s*\{([^}]*)\}/', $css, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public function test_every_button_class_used_in_views_is_defined_in_the_shipped_css(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $used = $this->usedButtonClasses();

        $this->assertNotEmpty($used, 'precondition: views use button classes');

        foreach ($used as $class) {
            $this->assertNotNull(
                $this->cssRule($css, $class),
                "Button class .{$class} is used in a view but defined nowhere in resources/css/app.css",
            );
        }
    }

    public function test_color_bearing_variants_carry_the_full_state_set(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        foreach (['btn-primary', 'btn-secondary', 'btn-ghost', 'btn-danger', 'btn-danger-ghost', 'btn-accent'] as $class) {
            $rule = $this->cssRule($css, $class);
            $this->assertNotNull($rule, ".{$class} must be defined");

            foreach (['bg-' => 'a background', 'text-' => 'a text color', 'hover:' => 'a hover state', 'active:' => 'an active state', 'disabled:' => 'a disabled state'] as $needle => $label) {
                $this->assertStringContainsString($needle, $rule, ".{$class} must define {$label}");
            }
        }

        // The focus state: the Section 6.6 ring applies to every
        // interactive element, and the primary button carries its
        // own on-fill variant so the ring stays visible against
        // the brand fill.
        $this->assertMatchesRegularExpression('/:focus-visible\s*\{[^}]*outline:\s*2px solid/', $css);
        $this->assertNotNull($this->cssRule($css, 'btn-primary:focus-visible'), '.btn-primary must define its focus-visible ring');
    }
}
