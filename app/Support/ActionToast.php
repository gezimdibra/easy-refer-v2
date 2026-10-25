<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * RS-14 (Train 2; GEN-01..04): the success-toast payloads the
 * notices region renders beside the status sentence.
 *
 * Two shapes: an UNDOABLE toast (a reversible action completed
 * instantly; its toast carries the GEN-01 "Undo" action) and an
 * OUTCOME toast (an irreversible action; its toast states the
 * outcome and carries a View link, GEN-04).
 *
 * The undo snapshot itself is REGULAR session data, not flash:
 * the flash is swept after the landing page renders, but the
 * owner's Undo click is a later request and must still find
 * the snapshot. It holds the raw prior values (restored
 * byte-for-byte), a one-time token (the toast form carries it
 * in its body, never a URL), the GEN-03 success sentence, the
 * landing route, and the 30-second expiry (the top of the
 * queue's 10-30s band). One slot: a newer reversible action
 * replaces the older snapshot, so only the latest action can
 * be undone — the same contract as the toasts owners see in
 * mainstream SaaS.
 */
class ActionToast
{
    public const WINDOW_SECONDS = 30;

    public const SESSION_KEY = 'undo_snapshot';

    /**
     * Arm an undoable toast for a reversible action that has
     * ALREADY completed. $snapshot is the action-specific
     * restore payload (consumed by UndoController by kind).
     */
    public static function undoable(Request $request, string $message, string $kind, array $snapshot, string $successLine, string $redirectRoute): void
    {
        $token = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'token' => $token,
            'kind' => $kind,
            'data' => $snapshot,
            'success' => $successLine,
            'redirect' => $redirectRoute,
            'expires_at' => now()->addSeconds(self::WINDOW_SECONDS)->timestamp,
        ]);

        $request->session()->flash('toast', [
            'message' => $message,
            'undo' => ['token' => $token],
        ]);
    }

    /**
     * Flash an outcome toast with a View link (GEN-04).
     */
    public static function outcome(Request $request, string $message, string $viewLabel, string $viewUrl): void
    {
        $request->session()->flash('toast', [
            'message' => $message,
            'view' => ['label' => $viewLabel, 'url' => $viewUrl],
        ]);
    }
}
