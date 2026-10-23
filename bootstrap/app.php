<?php

use App\Http\Middleware\AuthenticateWithRememberToken;
use App\Http\Middleware\EnforceSessionLifetime;
use App\Http\Middleware\EnsureStepUpIsFresh;
use App\Http\Middleware\EnsureTwoFactorChallengePassed;
use App\Http\Middleware\VerifyRequestOrigin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'session.lifetime' => EnforceSessionLifetime::class,
            'origin.check' => VerifyRequestOrigin::class,
            '2fa' => EnsureTwoFactorChallengePassed::class,
            'step-up' => EnsureStepUpIsFresh::class,
        ]);

        // Selector/validator remember-me resolution (spec Section 8.4),
        // after the session starts and before the route guard decides.
        $middleware->web(append: [
            AuthenticateWithRememberToken::class,
        ]);

        // Every protected owner route sits behind the single auth guard
        // group (spec Section 8.1). Guests land on the owner login;
        // authenticated owners land on their dashboard.
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
