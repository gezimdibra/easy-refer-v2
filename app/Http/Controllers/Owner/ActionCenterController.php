<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\Owner\ActionCenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Owner Action Center (RK-01, Train 2): one inbox in the
 * owner shell for every state waiting on the owner. The page
 * renders the read model's open items; dismissal posts back
 * here and stores the item's current fingerprint, so the row
 * stays hidden until the state itself changes.
 */
class ActionCenterController extends Controller
{
    public function index(Request $request, ActionCenter $center): View
    {
        $user = $request->user();
        $state = $center->pageState($user->business, $user);

        return view('owner.actions', [
            'business' => $user->business,
            'items' => $state['items'],
            // G3/Q3: true when states are open but every row
            // stands dismissed — the page then carries the
            // honest quiet line (AC-10), never "good standing".
            'quiet' => $state['quiet'],
        ]);
    }

    public function dismiss(Request $request, string $key, ActionCenter $center): RedirectResponse
    {
        $user = $request->user();

        abort_unless($center->dismiss($user->business, $user, $key), 404);

        return redirect()->route('actions.index');
    }
}
