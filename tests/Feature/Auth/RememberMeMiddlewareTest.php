<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\AuthenticateWithRememberToken;
use App\Models\User;
use App\Services\Auth\RememberMeService;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

/**
 * The remember-me middleware invoked directly on a fresh request:
 * this is the exact contract the web group relies on (a guest with a
 * valid cookie is signed in and receives the rotated cookie), without
 * the test harness's shared session store blurring two browsers into
 * one.
 */
class RememberMeMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TwoBusinessFixtureSeeder::class);
    }

    private function ownerA(): User
    {
        return User::where('email', TwoBusinessFixtureSeeder::A_EMAIL)->firstOrFail();
    }

    private function guestRequest(?string $cookieValue): Request
    {
        $request = Request::create(route('dashboard'), 'GET');

        if ($cookieValue !== null) {
            $request->cookies->set(RememberMeService::COOKIE_NAME, $cookieValue);
        }

        $session = new Store('middleware-test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        $request->setUserResolver(fn ($guard = null) => Auth::guard($guard)->user());

        return $request;
    }

    private function runMiddleware(Request $request): Response
    {
        return app(AuthenticateWithRememberToken::class)->handle(
            $request,
            fn () => new Response('passed')
        );
    }

    public function test_a_valid_cookie_signs_the_guest_in_and_queues_the_rotated_cookie(): void
    {
        $issued = app(RememberMeService::class)->issue($this->ownerA());

        $response = $this->runMiddleware($this->guestRequest($issued->getValue()));

        $this->assertSame('passed', $response->getContent());
        $this->assertTrue(Auth::check());
        $this->assertSame($this->ownerA()->id, Auth::id());

        $queued = Cookie::queued(RememberMeService::COOKIE_NAME);
        $this->assertNotNull($queued);
        $this->assertNotSame($issued->getValue(), $queued->getValue());
    }

    public function test_no_cookie_passes_through_as_guest(): void
    {
        $this->runMiddleware($this->guestRequest(null));

        $this->assertTrue(Auth::guest());
        $this->assertNull(Cookie::queued(RememberMeService::COOKIE_NAME));
    }

    public function test_an_invalid_cookie_passes_through_as_guest(): void
    {
        $this->runMiddleware($this->guestRequest('unknownselector1.whatever'));

        $this->assertTrue(Auth::guest());
        $this->assertNull(Cookie::queued(RememberMeService::COOKIE_NAME));
    }

    public function test_an_authenticated_request_is_not_reprocessed(): void
    {
        $user = $this->ownerA();
        $issued = app(RememberMeService::class)->issue($user);
        Auth::login($user);

        $this->runMiddleware($this->guestRequest($issued->getValue()));

        // No rotation happened: the middleware left the token alone.
        $this->assertNull(Cookie::queued(RememberMeService::COOKIE_NAME));
    }
}
