<?php

use App\Http\Middleware\AuthenticateWithRememberToken;
use App\Http\Middleware\CaptureAttribution;
use App\Http\Middleware\EnforceSessionLifetime;
use App\Http\Middleware\EnsureAdminTwoFactor;
use App\Http\Middleware\EnsureBusinessNotClosed;
use App\Http\Middleware\EnsureStepUpIsFresh;
use App\Http\Middleware\EnsureTwoFactorChallengePassed;
use App\Http\Middleware\VerifyRequestOrigin;
use App\Routing\Router as AppRouter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies are an explicit deployment decision
        // (security verifier m-7): the default trusts nothing, so
        // $request->ip() is the connection peer and every per-IP
        // budget keys on it. Where the host's proxy shape hides the
        // client address, set TRUSTED_PROXIES to the proxy's narrow
        // addresses in .env; never a wildcard, which would make
        // every DB budget header-spoofable at once.
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->alias([
            'session.lifetime' => EnforceSessionLifetime::class,
            'origin.check' => VerifyRequestOrigin::class,
            '2fa' => EnsureTwoFactorChallengePassed::class,
            'step-up' => EnsureStepUpIsFresh::class,
            'attribution' => CaptureAttribution::class,
            'owner.open' => EnsureBusinessNotClosed::class,
            'admin.2fa' => EnsureAdminTwoFactor::class,
        ]);

        // Provider webhook deliveries carry no CSRF token: Square
        // and Twilio authenticate their POSTs with their own
        // signature schemes, verified inside the webhook
        // controllers before anything is recorded or applied
        // (spec Sections 9.4 and 10.3). The exemption is scoped
        // to the webhook prefix only, which covers exactly the
        // two registered webhook routes (webhooks.square,
        // webhooks.twilio.status); every other route stays
        // token-checked. This list is pinned verbatim by
        // CsrfTest: widening it fails the suite on purpose. The
        // evidence for the change is the BU-8 production probe
        // (2026-10-24): with zero exemptions, real deliveries
        // were rejected 419 before signature verification ran.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        // Selector/validator remember-me resolution (spec Section 8.4),
        // after the session starts and before the route guard decides.
        $middleware->web(append: [
            AuthenticateWithRememberToken::class,
        ]);

        // Every protected owner route sits behind the single auth guard
        // group (spec Section 8.1). Guests land on the owner login;
        // authenticated owners land on their dashboard. The platform
        // console is a separate surface with its own guard (spec
        // Section 15): guests there go to the admin sign-in.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin', 'admin/*')
            ? route('admin.login')
            : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Deploy-defect fix (release 02, Defect 2, production entry
// correction): install the application router before anything
// can resolve a router. The production entry constructs the
// HTTP kernel before service providers register, and the kernel
// captures the router instance it is constructed with; a swap
// registered later, in a provider, therefore reaches the
// container binding but never the already-built kernel, which
// keeps a plain framework router with no routes and answers
// every routed page with a 404. Registering the extender here,
// immediately after the application is created and before it is
// returned, puts it ahead of the first router resolution in
// every entry order. The framework aliases the base router
// class and the routing contracts to the 'router' binding, so
// extending that one binding covers type-hinted resolutions as
// well: the kernel, the 'router' binding, and every type hint
// share the single application router instance, whose
// setCompiledRoutes() installs the application compiled route
// collection when a compiled route cache loads.
$app->extend('router', static fn ($resolved, $app) => $resolved instanceof AppRouter
    ? $resolved
    : new AppRouter($app->make(Dispatcher::class), $app));

return $app;
