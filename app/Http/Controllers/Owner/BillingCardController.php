<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Services\Billing\CardOnFileException;
use App\Services\Billing\CardOnFileService;
use App\Services\Billing\SquareOutcomeUnknown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Card capture for the billing page (billing UI spec Sections
 * 2 Block C and 3, build unit BU-4). The browser's Web Payments
 * SDK tokenizes the card inside Square-hosted fields; the only
 * value that ever reaches these actions is the single-use
 * source id, passed straight to CardOnFileService and never
 * stored or logged. Adding a card is an ordinary owner write;
 * replace and remove are the Section 8.7 step-up action class
 * (spec 9.9), enforced by the routes' step-up middleware.
 *
 * Outcome mapping follows CardResult, the engine's contract:
 * saved flashes BILL-07; a definitive decline answers BILL-03
 * with the form open for another try; a provisioning failure
 * answers BILL-04; an unknown outcome leaves the row pending
 * and the page itself renders BILL-05 from that row.
 */
class BillingCardController extends Controller
{
    public function store(Request $request, CardOnFileService $cards): RedirectResponse
    {
        return $this->save($request, $cards);
    }

    public function replace(Request $request, CardOnFileService $cards): RedirectResponse
    {
        return $this->save($request, $cards);
    }

    public function remove(Request $request, CardOnFileService $cards): RedirectResponse
    {
        $business = $request->user()->business;
        $card = PaymentMethod::defaultFor($business);

        if ($card !== null) {
            $cards->removeCard($business, $card, $request->user());
        }

        return redirect()->route('settings.billing');
    }

    private function save(Request $request, CardOnFileService $cards): RedirectResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'string', 'max:255'],
        ], [
            'source_id.required' => 'Some card details need another look.',
        ]);

        $business = $request->user()->business;

        try {
            $method = $cards->saveCard($business, $data['source_id'], $request->user());
        } catch (CardOnFileException) {
            return redirect()->route('settings.billing')->withErrors([
                'card' => 'We could not set up billing for your business. Try again, and contact support@easy-refer.com if it keeps happening.',
            ]);
        } catch (SquareOutcomeUnknown) {
            return redirect()->route('settings.billing');
        }

        if ($method->state !== PaymentMethod::STATE_SUCCEEDED) {
            return redirect()->route('settings.billing')->withErrors([
                'card' => 'Your card was declined. No charge was made. Check the details or try a different card.',
            ]);
        }

        return redirect()->route('settings.billing')->with(
            'status',
            'Card saved. It ends in '.$method->last4.' and is now the card we charge.',
        );
    }
}
