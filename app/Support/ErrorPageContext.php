<?php

namespace App\Support;

use Illuminate\Http\Request;
use Throwable;

/**
 * Error page context (gap pack 06, Variant A, ERR-3): an error that
 * arrives on an authenticated request, or on a URL inside the owner
 * portal (the auth/ route space), renders in the app layout with the
 * L1 / D1 portal tokens; every other error renders in the public
 * marketing layout, light-locked. Where the context cannot be known
 * reliably at render time (a 500 raised while the app is still
 * booting is the named case), determination fails closed to the
 * public skeleton: that fallback is the designed behavior, not a
 * defect.
 */
class ErrorPageContext
{
    public static function isApp(?Request $request = null): bool
    {
        try {
            $request ??= request();

            if (auth()->check()) {
                return true;
            }

            return $request->is('auth', 'auth/*');
        } catch (Throwable) {
            return false;
        }
    }

    public static function layout(?Request $request = null): string
    {
        return self::isApp($request) ? 'layouts.app' : 'layouts.marketing';
    }
}
