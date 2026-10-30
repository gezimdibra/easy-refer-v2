<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\QrCode;
use Illuminate\Http\RedirectResponse;

/**
 * The short link (QRK-06): /join/{slug}, the typed address
 * printed on every poster for customers who cannot scan
 * (REF-POSTER-02j). It resolves the business by its stored
 * slug and sends the customer to the counter code's landing,
 * the same join flow every printed code opens. An unknown slug
 * answers the designed 404.
 */
class JoinController extends Controller
{
    public function show(string $slug): RedirectResponse
    {
        $business = Business::query()->where('slug', $slug)->first();

        // The demo business (Amendment 63) has no join door: it
        // is reachable only through the landing's demo entry.
        if ($business === null || $business->isDemo()) {
            abort(404);
        }

        $qr = QrCode::activeForPlacement($business, 'counter');

        if ($qr !== null && $qr->plainToken() === null) {
            // Issued before sealed copies existed: it cannot be
            // re-presented, so it is revoked and reissued once,
            // the QR page's standing liveCode rule.
            $qr->forceFill(['state' => 'revoked', 'revoked_at' => now()])->save();
            $qr = null;
        }

        if ($qr === null) {
            QrCode::issueFor($business, null, 'counter');
            $qr = QrCode::activeForPlacement($business, 'counter');
        }

        return redirect()->route('refer.landing', ['token' => $qr->plainToken()]);
    }
}
