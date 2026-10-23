<?php

namespace App\Http\Controllers;

use App\Services\Attribution\AttributionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The consent banner's answer (spec Section 16, copy CONSENT-01).
 * Accept turns ad measurement on (the tags read the choice cookie);
 * decline deletes the er_attrib cookie and stops further capture
 * for this browser. The choice itself is remembered for a year.
 */
class ConsentController extends Controller
{
    public function store(Request $request, AttributionService $attribution): RedirectResponse
    {
        $choice = $request->input('choice') === 'accepted' ? 'accepted' : 'declined';

        $response = back();

        $response->headers->setCookie(Cookie::create(
            $attribution->consentCookieName(),
            $choice,
            now()->addDays(365)->timestamp,
            (string) config('session.path'),
            null,
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        ));

        if ($choice === 'declined') {
            // Deleting the attribution cookie: an already-expired
            // cookie under the same name and path.
            $response->headers->setCookie(Cookie::create(
                $attribution->cookieName(),
                '',
                now()->subYears(5)->timestamp,
                (string) config('session.path'),
                null,
                (bool) config('session.secure'),
                true,
                false,
                'lax',
            ));
        }

        return $response;
    }
}
