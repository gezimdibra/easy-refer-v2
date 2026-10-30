<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\Share\OgCardRenderer;
use Illuminate\Http\Response;

/**
 * RS-N1 (Train B, Amendment 39): the public Open Graph card
 * route. The URL carries only the business's public slug
 * (already printed on its posters) and a short content version
 * hash: no share token, no membership id, no customer data, and
 * no sequential identifier to enumerate.
 *
 * State is re-checked on EVERY fetch: the version must match
 * the business's current content (a mutated or superseded hash
 * is a 404), and a business that is not live at fetch time
 * serves the generic brand card, never its reward card, even
 * when handed a version minted while it was active. Responses
 * carry no immutable caching: a card fetched while active may
 * persist inside a chat app by that platform's own rules, but
 * nothing here tells a fetcher to keep it forever, and the
 * landing behind it remains the gate.
 */
class OgCardController extends Controller
{
    public function show(string $slug, string $version, OgCardRenderer $renderer): Response
    {
        $business = Business::query()->where('slug', $slug)->first();

        // The demo business (Amendment 63) is in no OG unfurl
        // pool: no card exists for it, generic or otherwise.
        if ($business === null || $business->isDemo() || ! hash_equals($renderer->versionFor($business), $version)) {
            abort(404);
        }

        $png = $business->flowIsActive()
            ? $renderer->rewardCardPng($business)
            : $renderer->genericCardPng();

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
