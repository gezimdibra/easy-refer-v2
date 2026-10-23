<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The reward text pair (spec Section 5.3, surfaced early by the
 * Overview starter-pack card in the design amendment Section
 * 6.4.1, which writes the pair directly). The full Settings
 * surface lands in Sprint 3; this endpoint is the one write path
 * both use.
 */
class RewardTextController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'friend_reward_text' => ['required', 'string', 'max:120'],
            'referrer_reward_text' => ['nullable', 'string', 'max:120'],
        ]);

        $business = $request->user()->business;

        $business->forceFill([
            'friend_reward_text' => $data['friend_reward_text'],
            'referrer_reward_text' => $data['referrer_reward_text'] ?? null,
        ])->save();

        AuditLog::write('business.reward_texts_updated', $request->user(), [], $business);

        return back()->with('status', 'Reward text saved.');
    }
}
