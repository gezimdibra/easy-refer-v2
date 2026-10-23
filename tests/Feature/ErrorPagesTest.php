<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Tests\TestCase;

/**
 * Branded error pages (Gate 3 finding on release 04): the codebase
 * shipped no errors/ views, so an unknown URL rendered the
 * framework's bare default 404 page. These tests pin the branded
 * pages in both directions: the brand markers and the way back are
 * present, and the framework default template signature is absent.
 * Every URL expectation derives from the route table, so the same
 * assertions hold at the root mount and the subdirectory mount.
 */
class ErrorPagesTest extends TestCase
{
    public function test_unknown_url_returns_the_branded_404_page(): void
    {
        $response = $this->get('/this-page-does-not-exist');

        $response->assertNotFound();
        $response->assertSee('Page not found');
        $response->assertSee('We could not find the page you were looking for.');
        $response->assertSee('Easy Refer');

        // The way back, at whichever mount the suite is running.
        $response->assertSee('href="'.route('home').'"', false);
        $response->assertSee('href="'.route('login').'"', false);

        // Not the framework's bare default template.
        $response->assertDontSee('normalize.css');
        $response->assertDontSee('<title>Not Found</title>', false);
    }

    public function test_a_server_error_renders_the_branded_500_page(): void
    {
        config(['app.debug' => false]);

        Route::get('/__error-probe', function () {
            throw new RuntimeException('error page probe');
        });

        $response = $this->get('/__error-probe');

        $response->assertServerError();
        $response->assertSee('Something went wrong');
        $response->assertSee('Something went wrong on our side while loading this page.');
        $response->assertSee('href="'.route('home').'"', false);
        $response->assertDontSee('normalize.css');
    }

    public function test_the_500_view_renders_branded_when_rendered_directly(): void
    {
        // The errors bag is what the web middleware shares with every
        // view during a real request; the direct render supplies it
        // the same way so the layout's footer can render.
        $html = view('errors.500', [
            'exception' => new RuntimeException('error page probe'),
            'errors' => new ViewErrorBag,
        ])->render();

        $this->assertStringContainsString('Something went wrong', $html);
        $this->assertStringContainsString('Easy Refer', $html);
        $this->assertStringContainsString('href="'.route('home').'"', $html);
        $this->assertStringNotContainsString('normalize.css', $html);
    }

    public function test_the_remaining_error_views_render_branded(): void
    {
        $expectations = [
            'errors.403' => 'You do not have access to this page',
            'errors.419' => 'Your session expired',
            'errors.429' => 'Too many requests',
        ];

        foreach ($expectations as $view => $heading) {
            $html = view($view, [
                'exception' => new RuntimeException('error page probe'),
                'errors' => new ViewErrorBag,
            ])->render();

            $this->assertStringContainsString($heading, $html, $view);
            $this->assertStringContainsString('Easy Refer', $html, $view);
            $this->assertStringContainsString('href="'.route('home').'"', $html, $view);
            $this->assertStringNotContainsString('normalize.css', $html, $view);
        }
    }
}
