<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CASCADE HIJACK class pins (owner findings batch 2, 2026-10-29).
 *
 * The generic rule .ui-scope button[type=submit]:not(.btn) once
 * restyled card-shaped submit buttons (the onboarding starter-pack
 * <button class="panel"> cards on production) into filled action
 * buttons whose text failed contrast in both schemes. These pins
 * hold the permanent guards: the generic rules exclude card shapes
 * and explicit text actions by name, and no blade may present a
 * card-shaped choice or an unclassed text action as a bare submit.
 */
class AffordanceCascadeTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    /** @return array<int, array{file:string, attrs:string}> */
    private function buttons(): array
    {
        $out = [];
        $views = resource_path('views');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $text = preg_replace('/\{\{--.*--\}\}/s', '', file_get_contents($file->getPathname()));
            if (preg_match_all('/<button\b([^>]*)>/s', $text, $m)) {
                foreach ($m[1] as $attrs) {
                    $out[] = ['file' => str_replace($views.'/', '', $file->getPathname()), 'attrs' => $attrs];
                }
            }
        }

        return $out;
    }

    private function classOf(string $attrs): string
    {
        return preg_match('/class\s*=\s*"([^"]*)"/', $attrs, $m) ? $m[1] : '';
    }

    private function isSubmit(string $attrs): bool
    {
        // A button with no type inside a form defaults to submit.
        return ! preg_match('/type\s*=\s*"(button|reset)"/', $attrs);
    }

    public function test_generic_submit_rules_exclude_card_shapes_and_text_actions(): void
    {
        $css = $this->css();
        foreach (['.ui-scope', '.auth-scope'] as $scope) {
            $pattern = '/'.preg_quote($scope, '/').' button\[type=\'submit\'\][^{]*\{/';
            $this->assertSame(1, preg_match($pattern, $css, $m), "generic submit rule missing for {$scope}");
            foreach ([':not(.btn)', ':not(.panel)', ':not(.card)', ':not(.tile)', ':not(.text-action)'] as $guard) {
                $this->assertStringContainsString($guard, $m[0], "{$scope} submit rule lost its {$guard} guard");
            }
        }
    }

    public function test_no_card_shaped_choice_is_a_bare_submit_button(): void
    {
        foreach ($this->buttons() as $b) {
            if (! $this->isSubmit($b['attrs'])) {
                continue;
            }
            $classes = preg_split('/\s+/', $this->classOf($b['attrs'])) ?: [];
            foreach (['panel', 'card', 'tile'] as $shape) {
                $this->assertNotContains($shape, $classes, "Card-shaped submit button in {$b['file']}: {$b['attrs']}");
            }
        }
    }

    public function test_every_submit_button_is_a_btn_or_a_text_action(): void
    {
        foreach ($this->buttons() as $b) {
            if (! $this->isSubmit($b['attrs'])) {
                continue;
            }
            // The app-rail sign-out is a nav row, rendered outside
            // .ui-scope (layouts/app places the rail in <nav>, the
            // scope starts at <main>); it is ruled not-a-finding in
            // the affordance train report and is exempt here.
            if ($b['file'] === 'partials/app-rail.blade.php') {
                continue;
            }
            $classes = preg_split('/\s+/', $this->classOf($b['attrs'])) ?: [];
            $ok = (bool) array_filter($classes, fn ($c) => $c === 'btn' || str_starts_with($c, 'btn-') || $c === 'text-action');
            $this->assertTrue($ok, "Bare submit button (no .btn / .text-action) in {$b['file']}: {$b['attrs']}");
        }
    }

    public function test_text_action_class_carries_the_44px_target_and_link_signals(): void
    {
        $css = $this->css();
        $this->assertSame(1, preg_match('/\.text-action\s*\{([^}]*)\}/s', $css, $m));
        $this->assertStringContainsString('min-height: 44px', $m[1]);
        $this->assertStringContainsString('text-link', $m[1]);
        $this->assertStringContainsString('underline', $m[1]);
        $this->assertStringContainsString('font-semibold', $m[1]);
    }
}
