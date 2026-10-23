<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * CSRF rejection evidence (matrix AUTH-27; finding G-3). The PHPUnit
 * harness cannot produce this evidence end to end: the framework
 * test client deliberately bypasses CSRF verification while running
 * unit tests (VerifyCsrfToken checks runningUnitTests()), so no
 * full-stack POST in this suite says anything about CSRF. What can
 * be proven, and is proven here, is the strongest evidence that
 * exists short of a browser: the framework middleware registered in
 * the web group, with zero exemptions anywhere in the application,
 * rejects a state-changing request carrying a missing, wrong, or
 * foreign-session token when it actually runs. The tests execute
 * the middleware directly with the app env temporarily switched out
 * of "testing" (the one switch that disables it) and restore the env
 * afterwards.
 */
class CsrfTest extends TestCase
{
    use RefreshDatabase;

    private function sessionWithToken(string $token): Store
    {
        $session = new Store('csrf-evidence', new ArraySessionHandler(120));
        $session->start();
        $session->put('_token', $token);

        return $session;
    }

    private function runMiddleware(Request $request): Response
    {
        $previous = $this->app['env'];
        $this->app->instance('env', 'local');

        try {
            $middleware = new ValidateCsrfToken($this->app, $this->app->make('encrypter'));

            return $middleware->handle($request, fn () => new Response('passed', 200));
        } finally {
            $this->app->instance('env', $previous);
        }
    }

    private function loginRequest(Store $session, array $parameters = [], array $headers = []): Request
    {
        $request = Request::create('http://localhost/new/auth/login', 'POST', $parameters);
        $request->setLaravelSession($session);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    public function test_the_web_group_runs_the_framework_csrf_middleware(): void
    {
        // The default groups are synced to the router when the HTTP
        // kernel first handles a request.
        $this->get(route('home'))->assertOk();

        $groups = $this->app->make('router')->getMiddlewareGroups();

        $this->assertContains(
            ValidateCsrfToken::class,
            $groups['web'],
            'The web middleware group must run the framework CSRF middleware'
        );
    }

    public function test_the_application_declares_no_csrf_exemptions(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString('validateCsrfTokens', $bootstrap);
    }

    public function test_a_missing_token_is_rejected(): void
    {
        $this->expectException(TokenMismatchException::class);

        $this->runMiddleware($this->loginRequest(
            $this->sessionWithToken('the-session-token'),
            ['identifier' => 'a@example.test', 'password' => 'irrelevant']
        ));
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        $this->expectException(TokenMismatchException::class);

        $this->runMiddleware($this->loginRequest(
            $this->sessionWithToken('the-session-token'),
            ['_token' => 'some-other-token']
        ));
    }

    public function test_a_token_from_another_session_is_rejected(): void
    {
        $foreign = $this->sessionWithToken('a-different-sessions-token');

        $this->expectException(TokenMismatchException::class);

        $this->runMiddleware($this->loginRequest(
            $this->sessionWithToken('the-session-token'),
            ['_token' => $foreign->token()]
        ));
    }

    public function test_the_sessions_own_token_passes_in_the_body_and_in_the_header(): void
    {
        $session = $this->sessionWithToken('the-session-token');

        $this->assertSame('passed', $this->runMiddleware($this->loginRequest(
            $session,
            ['_token' => 'the-session-token']
        ))->getContent());

        $this->assertSame('passed', $this->runMiddleware($this->loginRequest(
            $session,
            [],
            ['X-CSRF-TOKEN' => 'the-session-token']
        ))->getContent());
    }

    public function test_reading_verbs_are_not_challenged(): void
    {
        $request = Request::create('http://localhost/new/auth/login', 'GET');
        $request->setLaravelSession($this->sessionWithToken('the-session-token'));

        $this->assertSame('passed', $this->runMiddleware($request)->getContent());
    }
}
