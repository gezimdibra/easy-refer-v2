<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Tests\TestCase;

/**
 * Error pages, gap pack 06 (Variant A): per-code icon wells (ERR-1),
 * honest actions (ERR-2), the app/public context split (ERR-3) and
 * the absence pins (ERR-4). Headings and ledes are shipped copy and
 * stay verbatim; the only new strings are Amendment 29 [ERR-1] and
 * [ERR-2], verbatim.
 */
class ErrorPagesDesignTest extends TestCase
{
    use RefreshDatabase;

    private function renderError(string $view, ?Request $request = null): string
    {
        if ($request !== null) {
            $this->app->instance('request', $request);
        }

        return view($view, [
            'exception' => new RuntimeException('error page probe'),
            'errors' => new ViewErrorBag,
        ])->render();
    }

    private function requestFor(string $path, array $server = []): Request
    {
        return Request::create('http://localhost'.config('app.base_path', '').$path, 'GET', [], [], [], $server);
    }

    // ERR-1: five distinguishable icons, tint per code, icon + text.
    public function test_each_error_page_renders_its_own_icon_well_and_tint(): void
    {
        $expectations = [
            'errors.403' => ['lock', 'neutral'],
            'errors.404' => ['search', 'neutral'],
            'errors.419' => ['log-out', 'amber'],
            'errors.429' => ['trending-up', 'amber'],
            'errors.500' => ['circle-alert', 'error'],
        ];

        foreach ($expectations as $view => [$icon, $tint]) {
            $html = $this->renderError($view);
            $this->assertStringContainsString('data-error-icon="'.$icon.'"', $html, $view);
            $this->assertStringContainsString('data-error-tint="'.$tint.'"', $html, $view);
            $this->assertStringContainsString('error-icon-well', $html, $view);
        }
    }

    public function test_icon_well_tint_classes_follow_the_pack(): void
    {
        $neutral = $this->renderError('errors.404');
        $this->assertStringContainsString('bg-surface-2', $neutral);

        $amber = $this->renderError('errors.419');
        $this->assertStringContainsString('bg-warning-fill', $amber);
        $this->assertStringContainsString('text-warning-text', $amber);

        $error = $this->renderError('errors.500');
        $this->assertStringContainsString('bg-error-fill', $error);
        $this->assertStringContainsString('text-error-text', $error);
    }

    // ERR-2: honest actions in the public context.
    public function test_419_primary_returns_to_the_referring_form(): void
    {
        $form = 'http://localhost/auth/register';
        $request = $this->requestFor('/auth/register', ['HTTP_REFERER' => $form]);
        $html = $this->renderError('errors.419', $request);

        $this->assertStringContainsString('>Go back and try again</a>', $html);
        $this->assertStringContainsString('href="'.$form.'"', $html);
    }

    public function test_419_primary_falls_back_to_home_without_a_referrer(): void
    {
        $html = $this->renderError('errors.419', $this->requestFor('/newsletter'));

        $this->assertStringContainsString('>Go back and try again</a>', $html);
        $this->assertStringContainsString('href="'.route('home').'"', $html);
    }

    public function test_429_gains_a_try_again_action_that_reloads_the_current_page(): void
    {
        $request = $this->requestFor('/newsletter');
        $html = $this->renderError('errors.429', $request);

        $this->assertStringContainsString('>Try again</a>', $html);
        $this->assertStringContainsString('href="'.$request->fullUrl().'"', $html);
        $this->assertStringContainsString('>Go to the home page</a>', $html);
    }

    public function test_403_carries_the_support_line_in_both_contexts(): void
    {
        $sentence = 'If you think you should have access, contact us at support@easy-refer.com.';
        $public = strip_tags($this->renderError('errors.403'));
        $this->assertStringContainsString($sentence, $public);
    }

    // ERR-3: app context renders in the app layout, L2 / D2.
    public function test_app_context_renders_in_the_app_layout_for_every_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['errors.403', 'errors.404', 'errors.419', 'errors.429', 'errors.500'] as $view) {
            $html = $this->renderError($view, $this->requestFor('/auth/dashboard'));
            $this->assertStringContainsString('portal-theme', $html, $view);
            $this->assertStringContainsString('aria-label="Primary"', $html, $view);
            // The portal top token in both schemes (SEAM-01 pair).
            $this->assertStringContainsString('content="#FFFFFF"', $html, $view);
            $this->assertStringContainsString('content="#161B22"', $html, $view);
        }
    }

    public function test_app_context_actions_point_back_into_the_product(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $request = $this->requestFor('/auth/dashboard');

        foreach (['errors.403', 'errors.404', 'errors.500'] as $view) {
            $html = $this->renderError($view, $request);
            $this->assertStringContainsString('>Back to Overview</a>', $html, $view);
            $this->assertStringContainsString('href="'.route('dashboard').'"', $html, $view);
            $this->assertStringContainsString('>Go back</a>', $html, $view);
        }

        $expired = $this->renderError('errors.419', $request);
        $this->assertStringContainsString('>Go back and try again</a>', $expired);
        $this->assertStringContainsString('>Go back</a>', $expired);

        $limited = $this->renderError('errors.429', $request);
        $this->assertStringContainsString('>Try again</a>', $limited);
        $this->assertStringContainsString('>Back to Overview</a>', $limited);

        $forbidden = strip_tags($this->renderError('errors.403', $request));
        $this->assertStringContainsString('If you think you should have access, contact us at support@easy-refer.com.', $forbidden);
    }

    public function test_portal_path_alone_selects_the_app_context(): void
    {
        $html = $this->renderError('errors.404', $this->requestFor('/auth/qr'));
        $this->assertStringContainsString('portal-theme', $html);
        $this->assertStringContainsString('>Back to Overview</a>', $html);
    }

    public function test_public_context_stays_marketing_and_light_locked(): void
    {
        $html = $this->renderError('errors.404', $this->requestFor('/about'));
        $this->assertStringContainsString('id="site-header"', $html);
        $this->assertStringContainsString('landing-light-lock', $html);
        $this->assertStringNotContainsString('portal-theme', $html);
        // The light lock drops the marketing layout's dark tint.
        $this->assertStringNotContainsString('content="#221D17"', $html);
        $this->assertStringContainsString('>Sign in</a>', $html);
    }

    public function test_http_404_contexts_follow_auth_and_path(): void
    {
        $guest = $this->get('/this-page-does-not-exist');
        $guest->assertNotFound();
        $guest->assertSee('landing-light-lock', false);
        $guest->assertSee('Go to the home page');
        $guest->assertDontSee('portal-theme', false);

        $user = User::factory()->create();
        $signedIn = $this->actingAs($user)->get('/this-page-does-not-exist');
        $signedIn->assertNotFound();
        $signedIn->assertSee('portal-theme', false);
        $signedIn->assertSee('Back to Overview');
        $signedIn->assertDontSee('href="'.route('login').'"', false);

        $portal = $this->actingAs($user)->get('/auth/this-page-does-not-exist');
        $portal->assertNotFound();
        $portal->assertSee('portal-theme', false);
        $portal->assertSee('Back to Overview');
    }

    public function test_http_403_and_500_on_portal_paths_render_the_app_context(): void
    {
        \Illuminate\Support\Facades\Route::get('/auth/__forbidden-probe', function () {
            abort(403);
        });
        \Illuminate\Support\Facades\Route::get('/auth/__error-probe', function () {
            throw new RuntimeException('app context probe');
        });
        config(['app.debug' => false]);

        $user = User::factory()->create();

        $forbidden = $this->actingAs($user)->get('/auth/__forbidden-probe');
        $forbidden->assertForbidden();
        $forbidden->assertSee('portal-theme', false);
        $forbidden->assertSee('Back to Overview');

        $error = $this->actingAs($user)->get('/auth/__error-probe');
        $error->assertServerError();
        $error->assertSee('portal-theme', false);
        $error->assertSee('Back to Overview');
    }

    // ERR-4: the three absences, asserted per context.
    public function test_no_sign_in_action_is_rendered_to_a_signed_in_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['errors.403', 'errors.404', 'errors.419', 'errors.429', 'errors.500'] as $view) {
            $html = $this->renderError($view, $this->requestFor('/auth/dashboard'));
            $this->assertStringNotContainsString('href="'.route('login').'"', $html, $view);
            $this->assertStringNotContainsString('>Sign in<', $html, $view);
        }
    }

    public function test_no_app_context_error_renders_marketing_chrome(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['errors.403', 'errors.404', 'errors.419', 'errors.429', 'errors.500'] as $view) {
            $html = $this->renderError($view, $this->requestFor('/auth/dashboard'));
            $this->assertStringNotContainsString('id="site-header"', $html, $view);
            $this->assertStringNotContainsString('landing-light-lock', $html, $view);
        }
    }

    public function test_no_error_page_renders_a_framework_default(): void
    {
        $user = User::factory()->create();

        foreach ([false, true] as $signedIn) {
            if ($signedIn) {
                $this->actingAs($user);
            } else {
                Auth::logout();
            }
            foreach (['errors.403', 'errors.404', 'errors.419', 'errors.429', 'errors.500'] as $view) {
                $html = $this->renderError($view, $this->requestFor($signedIn ? '/auth/dashboard' : '/about'));
                $this->assertStringNotContainsString('normalize.css', $html, $view);
                $this->assertStringNotContainsString('<title>Not Found</title>', $html, $view);
                $this->assertStringNotContainsString('<title>Forbidden</title>', $html, $view);
            }
        }
    }

    public function test_headings_and_ledes_stay_verbatim(): void
    {
        $copy = [
            'errors.403' => ['You do not have access to this page', 'This page is only available to people with the right access for it.'],
            'errors.404' => ['Page not found', 'We could not find the page you were looking for. The link may be out of date, or the address may have been typed incorrectly.'],
            'errors.419' => ['Your session expired', 'For your security, this form expired because the page was open too long. Please try again.'],
            'errors.429' => ['Too many requests', 'You have sent a lot of requests in a short time, so we need you to slow down for a moment. Wait a minute, then try again.'],
            'errors.500' => ['Something went wrong', 'Something went wrong on our side while loading this page. Please try again in a few minutes.'],
        ];

        foreach ($copy as $view => [$heading, $lede]) {
            $html = $this->renderError($view);
            $this->assertStringContainsString($heading, $html, $view);
            $this->assertStringContainsString($lede, $html, $view);
        }
    }
}
