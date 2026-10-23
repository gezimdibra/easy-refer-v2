<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Owner home (spec Section 5). Phase 1 shows the business profile and
 * the email verification gate; the statistics defined in Section 5.1
 * land in Phase 3.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('owner.dashboard', [
            'user' => $user,
            'business' => $user->business,
        ]);
    }
}
