<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Support\ActionToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * RS-14 (Train 2; GEN-01..03): the one Undo endpoint behind
 * every reversible action's success toast. The token must
 * match the session snapshot and arrive inside its 30-second
 * window; anything else — expired, replayed, forged — flashes
 * GEN-02 and changes nothing. A successful undo consumes the
 * snapshot (one-time) and restores the snapshotted raw values
 * byte-for-byte.
 */
class UndoController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $business = $user->business;
        $snapshot = $request->session()->get(ActionToast::SESSION_KEY);
        $token = (string) $request->input('undo_token', '');

        $valid = is_array($snapshot)
            && hash_equals((string) ($snapshot['token'] ?? ''), $token)
            && now()->timestamp <= (int) ($snapshot['expires_at'] ?? 0);

        if (! $valid) {
            $request->session()->forget(ActionToast::SESSION_KEY);

            return back()->with('status', 'That can no longer be undone.');
        }

        $request->session()->forget(ActionToast::SESSION_KEY);
        $data = $snapshot['data'] ?? [];

        match ($snapshot['kind']) {
            'customer_removed' => $this->restoreCustomer($request, $business, $data),
            'reward_texts' => $this->restoreRewardTexts($request, $business, $data),
            default => null,
        };

        return redirect()->route($snapshot['redirect'])
            ->with('status', $snapshot['success']);
    }

    private function restoreCustomer(Request $request, $business, array $data): void
    {
        $membership = Membership::query()
            ->where('business_id', $business->id)
            ->findOrFail($data['membership_id']);

        $membership->forceFill([
            'removed_at' => $data['removed_at'],
            'share_token_state' => $data['share_token_state'],
        ])->save();

        AuditLog::write('customer.restored_to_store', $request->user(), [], $business, $membership);
    }

    private function restoreRewardTexts(Request $request, $business, array $data): void
    {
        $business->forceFill([
            'friend_reward_text' => $data['friend_reward_text'],
            'referrer_reward_text' => $data['referrer_reward_text'],
        ])->save();

        AuditLog::write('business.reward_texts_restored', $request->user(), [], $business);
    }
}
