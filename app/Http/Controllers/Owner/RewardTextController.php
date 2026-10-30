<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\StarterPacks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The signup / dashboard reward write path (spec Section 5.3).
 * ONE-REWARD (OR-1, design pass §2.4/§3.2): writes from these
 * surfaces are ALWAYS UNIFIED — the payload is the single
 * friend_reward_text field (the wire name is kept; the labels
 * are owner-visible, the field name is not), and a hand-crafted
 * POST carrying a referrer value still stores UNIFIED, because
 * this surface cannot create a split. Splitting lives only in
 * Settings. Both writes route through the canonical writer on
 * Business so flag and columns can never drift.
 *
 * The onboarding step's accept path (§3.2 E): when the step
 * posts advance=1 and the submitted text is a pack text (the
 * prefilled starter or any pack for the type — i.e. the owner
 * accepted a presented answer rather than authoring one), the
 * save advances straight to the Card step in one tap. An
 * edited (non-pack) text returns to the step, whose done panel
 * reads the reward back before Continue — readback is a truth
 * device, not friction.
 */
class RewardTextController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'friend_reward_text' => ['required', 'string', 'max:120'],
        ]);

        $business = $request->user()->business;

        // W3 (Amendment 38, Train A3-8): same staleness reminder
        // as the Settings save, under the same two conditions
        // (a text changed AND a poster download exists). A
        // configuration change (a split business re-saved from
        // a unified-only surface) counts as a change.
        $textsChanged = (string) ($business->getRawOriginal('friend_reward_text') ?? '') !== (string) $data['friend_reward_text']
            || $business->rewardsAreSplit()
            || filled($business->getRawOriginal('referrer_reward_text'));
        $hasDownload = \App\Models\ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'poster_downloaded')
            ->exists();

        $business->setRewardConfiguration((string) $data['friend_reward_text'], false, null);

        AuditLog::write('business.reward_texts_updated', $request->user(), [], $business);

        $response = back()->with('status', ($textsChanged && $hasDownload)
            ? 'Reward text saved. Your printed posters still show the old rewards. Print fresh posters from your QR page.'
            : 'Reward text saved.');

        if ($request->boolean('advance')) {
            $packTexts = collect(StarterPacks::forType($business->business_type))
                ->pluck('reward')
                ->map(fn ($text) => trim((string) $text))
                ->all();

            $presented = trim((string) $request->input('presented_reward_text', ''));

            if (in_array(trim((string) $data['friend_reward_text']), $packTexts, true)
                || ($presented !== '' && trim((string) $data['friend_reward_text']) === $presented)) {
                return redirect()->route('onboarding.step', 'card')
                    ->with('status', 'Reward set.');
            }

            return redirect()->route('onboarding.step', 'rewards');
        }

        return $response;
    }
}
