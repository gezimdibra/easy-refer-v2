<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterBusinessRequest;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(\Illuminate\Http\Request $request): View
    {
        // E2 signup_start (spec Section 16): the first interaction
        // with wizard Step 1, recorded once per session. Prefills
        // from the value calculator and the vertical pages ride the
        // signup session only (Section 2.6), never the URL beyond
        // this landing.
        if (! $request->session()->has('signup.e2_recorded')) {
            $request->session()->put('signup.e2_recorded', true);
            app(\App\Services\Attribution\AttributionService::class)->record('E2');
        }

        $prefill = $request->session()->get('signup.prefill', []);

        if (in_array($request->query('type'), Business::TYPES, true)) {
            $prefill['business_type'] = $request->query('type');
        }

        if (is_numeric($request->query('ticket'))) {
            $prefill['average_ticket_dollars'] = max(0, min(100000, (int) $request->query('ticket')));
        }

        if ($prefill !== []) {
            $request->session()->put('signup.prefill', $prefill);
        }

        // G6 demo phone prefill (Amendment 63 C-2, A-18
        // Finding 1): read from the LIVE demo session at
        // render time, never from a copy in this main session.
        // DemoSessionManager::fromRequest returns null for an
        // expired session (and deletes it on sight), so after
        // demo expiry this renders NO prefill. Any stale copy
        // an older build left in the main session is dropped
        // unread, so it can never be rendered either.
        $request->session()->forget('demo.carryover_phone');
        $liveDemo = app(\App\Services\Demo\DemoSessionManager::class)->fromRequest($request);
        $demoPhone = $liveDemo !== null ? ($liveDemo->data['draft']['phone'] ?? null) : null;

        // G1 claim hand-off (§3.2): when this session passed a
        // claimable page's gate (or an approved review hand-off),
        // the snapshot rides the existing prefill and the listed
        // phone is the Step-2 prefill, labelled by G1-C26 (§5.4).
        $claimPage = $this->armedClaimPage($request);
        if ($claimPage !== null) {
            $demoPhone = $claimPage->listed_phone_e164;
        }

        return view('auth.register', [
            'claimPage' => $claimPage,
            'prefillType' => $prefill['business_type'] ?? null,
            // G6 demo carryover (Amendment 63, Section 8 C-2/C-4):
            // the shop name and phone ride in as prefills only
            // from a live demo session's exit; they are the
            // prospect's own typing, offered back in their own
            // signup form, never a saved record.
            'prefillName' => $prefill['business_name'] ?? null,
            'prefillPhone' => is_string($demoPhone) && $demoPhone !== '' ? $demoPhone : null,
        ]);
    }

    /**
     * The identity checkpoint (spec Section 4.1; RS2-2 Variant A):
     * create the business, its owner, and the business's launch QR
     * code in one transaction the moment Steps 1-3 are submitted,
     * grant the one-time free visit credit (OD-1: 5 verified
     * visits), and log the owner in. From this moment the signup
     * PERSISTS: an owner who leaves returns by signing in, and the
     * onboarding flow resumes at its first open step with every
     * answer intact. The address is not collected here (the
     * onboarding Location step owns it; the address columns are
     * nullable until then), and NO Square customer is created
     * here: Square provisioning is deferred until the card moment
     * (CardOnFileService) or the Location save, whichever comes
     * first, so no Square object exists for a half-finished
     * signup. The database unique indexes on the canonical
     * identity back the form-level duplicate check: a racer who
     * slips between the check and the insert gets the same
     * generic error, never a 500, and never a second account:
     * the generic message's sign-in path IS the resume path for
     * an already-persisted checkpoint account.
     */
    /**
     * The claimable page this session is armed to claim, if any
     * (G1 §3.2): set only by the claim gate or an approved review
     * hand-off, and only while the page is still claimable.
     */
    private function armedClaimPage(\Illuminate\Http\Request $request): ?\App\Models\ClaimablePage
    {
        $id = $request->session()->get('claimable.claim_page_id');
        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        $page = \App\Models\ClaimablePage::query()->find((int) $id);

        return $page !== null && ($page->isOpenable() || $page->status === \App\Models\ClaimablePage::STATUS_PENDING_REVIEW)
            ? $page : null;
    }

    /**
     * Review item 1a: re-read the claimable page with a row lock
     * inside the checkpoint transaction and refuse unless its
     * status is still claimable (openable, or pending_review for
     * an approved review hand-off). Throws ClaimCheckpointRaceLost
     * carrying the observed status; the transaction rolls back, so
     * a lost race creates nothing.
     */
    private function lockClaimablePageOrRefuse(\App\Models\ClaimablePage $page): \App\Models\ClaimablePage
    {
        $locked = \App\Models\ClaimablePage::query()->whereKey($page->id)->lockForUpdate()->first();

        if ($locked === null
            || ! ($locked->isOpenable() || $locked->status === \App\Models\ClaimablePage::STATUS_PENDING_REVIEW)) {
            throw new \App\Services\Claimable\ClaimCheckpointRaceLost($page->id, $locked?->status);
        }

        return $locked;
    }

    public function store(RegisterBusinessRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // G1 §8.4: the checkpoint re-checks the page's status
        // inside its own step. A claim armed in this session whose
        // page was removed (or expired/killed) before this POST
        // refuses with the true state (G1-C37) and creates NOTHING.
        $claimPage = $this->armedClaimPage($request);
        if ($request->session()->has('claimable.claim_page_id') && $claimPage === null) {
            // §9.2 (review item 3): a refusal BECAUSE the page was
            // removed is recorded at this C37 site, so the
            // blocked-claim count is computable from storage.
            $blockedPage = \App\Models\ClaimablePage::query()
                ->find((int) $request->session()->get('claimable.claim_page_id'));
            if ($blockedPage !== null && $blockedPage->status === \App\Models\ClaimablePage::STATUS_REMOVED) {
                $blockedPage->recordEvent('claim_blocked_removed', ['site' => 'checkpoint']);
            }
            $request->session()->forget('claimable.claim_page_id');
            throw ValidationException::withMessages([
                'account' => \App\Support\ClaimableCopy::c37(),
            ]);
        }

        try {
            $user = DB::transaction(function () use ($data, $request, $claimPage) {
                if ($claimPage !== null) {
                    // Review item 1a: the pre-check above read the
                    // page BEFORE this transaction, so it cannot
                    // decide a race. Lock the page row and re-check
                    // its status INSIDE the transaction, before
                    // anything is created: a competing checkpoint
                    // serializes on this lock and then observes the
                    // winner's flip; a removal that committed first
                    // is observed here too. The schema backstop is
                    // the unique index on businesses.claimable_page_id
                    // (migration 000041).
                    $claimPage = $this->lockClaimablePageOrRefuse($claimPage);
                }

                $business = Business::create([
                    'name' => $data['business_name'],
                    'business_type' => $data['business_type'] ?? null,
                    'contact_email' => $data['email'],
                    'contact_phone_e164' => $request->input('phone_e164'),
                    'free_visits_remaining' => (int) config('billing.free_visits_at_signup'),
                    // OD-1: every business starts on the launch plan;
                    // prices are read from the plans table everywhere.
                    'current_plan_id' => \App\Models\Plan::launch()?->id,
                    // G1 §3.2: claim attribution only. No money,
                    // reward, or activation predicate reads these.
                    'claim_source' => $claimPage !== null ? 'claimable_page' : null,
                    'claimable_page_id' => $claimPage?->id,
                ]);

                if ($claimPage !== null) {
                    // Review item 1a, flip point: re-read the locked
                    // page once more immediately before the flip, so
                    // a state change that landed while this
                    // transaction ran (a winner's claim flip, or a
                    // removal — §8.4: a removal committed mid-
                    // checkpoint is never overwritten back to
                    // claimed) refuses the flip instead of being
                    // overwritten by it.
                    $claimPage = $this->lockClaimablePageOrRefuse($claimPage);

                    // The head start (§3.2): the snapshot address
                    // prefills the Location step (its form reads
                    // the business row) and the demo's ONE starter
                    // reward preselects the onboarding reward step
                    // (one reward, both sides — the signup never
                    // asks twice). Both stay owner-editable in the
                    // ordinary steps; nothing is skipped.
                    $business->forceFill([
                        'street' => $claimPage->street,
                        'city' => $claimPage->city,
                        'state' => $claimPage->state,
                        'postal_code' => $claimPage->postal_code,
                        'friend_reward_text' => $claimPage->starter_reward_text,
                        'referrer_reward_text' => $claimPage->starter_reward_text,
                    ])->save();

                    // The page becomes CLAIMED at exactly this
                    // checkpoint (§3.2 step 3), in the same
                    // transaction: a racer or a removal that lost
                    // the race cannot half-claim it. Photo ref and
                    // hours drop here (§5 P5) — the business's own
                    // data now lives in its account.
                    $claimPage->forceFill([
                        'status' => \App\Models\ClaimablePage::STATUS_CLAIMED,
                        'claimed_business_id' => $business->id,
                        'claimed_at' => now(),
                        'photo_ref' => null,
                        'opening_hours' => null,
                    ])->save();
                    $claimPage->recordEvent('claimed');
                }

                $user = User::create([
                    'business_id' => $business->id,
                    'role' => 'owner',
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'canonical_email' => $request->input('canonical_email'),
                    'phone_e164' => $request->input('phone_e164'),
                    'password' => $data['password'],
                ]);

                QrCode::issueFor($business);

                return $user;
            });
        } catch (\App\Services\Claimable\ClaimCheckpointRaceLost $lost) {
            // The losing transaction has rolled back: nothing was
            // created. The removal case records its §9.2 blocked
            // event HERE, outside the rolled-back transaction, from
            // the status observed under the lock. The refusal is
            // the designed outcome (G1-C37).
            $request->session()->forget('claimable.claim_page_id');
            if ($lost->observedStatus === \App\Models\ClaimablePage::STATUS_REMOVED) {
                \App\Models\ClaimablePage::query()->find($lost->pageId)
                    ?->recordEvent('claim_blocked_removed', ['site' => 'checkpoint']);
            }
            throw ValidationException::withMessages([
                'account' => \App\Support\ClaimableCopy::c37(),
            ]);
        } catch (QueryException) {
            // Review item 1a, schema-backstop path: if the query
            // failure is the unique index on
            // businesses.claimable_page_id firing because a
            // competing checkpoint already claimed this page, the
            // loser gets the designed claim refusal (C37), not the
            // generic duplicate-account message — a business that
            // claims this page is the proof the race was lost. A
            // genuine identity duplicate (no business claims the
            // page) keeps the generic message below.
            if ($claimPage !== null
                && Business::query()->where('claimable_page_id', $claimPage->id)->exists()) {
                $request->session()->forget('claimable.claim_page_id');
                throw ValidationException::withMessages([
                    'account' => \App\Support\ClaimableCopy::c37(),
                ]);
            }

            throw ValidationException::withMessages([
                'account' => 'An account with these details already exists. Sign in or reset your password.',
            ]);
        }

        // OWNER FINDING 7 + the owner's amendment of 2026-10-29
        // ("wait till they use it for a day at least and havent
        // verified yet"): registration sends exactly ONE email,
        // the verification email. The Registered event's only
        // listener in this app is the framework's
        // SendEmailVerificationNotification (auto-wired by the
        // framework's EventServiceProvider when the app defines
        // none of its own; there are no app listeners, observers,
        // or User/Business boot hooks on this event), which calls
        // User::sendEmailVerificationNotification() and sends the
        // app's VerifyEmailNotification. The WelcomeMail is NOT
        // sent here any more: it is the unverified nudge, sent
        // once by the scheduled WelcomeNudgeService at 24+ hours
        // when the address is still unverified. Verification
        // resends go through EmailVerificationNotificationController
        // and send the same verification notification, never the
        // welcome mail.
        event(new Registered($user));

        // Section 16: the first touch is written onto the business
        // at signup, and E3 signup_complete fires server-side. The
        // calculator's ticket estimate becomes the business's
        // average ticket (Section 2.6 prefill).
        $attribution = app(\App\Services\Attribution\AttributionService::class);

        if (($payload = $attribution->captured($request)) !== null) {
            $attribution->captureForBusiness($user->business, $payload);
        }

        $attribution->record('E3', $user->business);

        $ticketDollars = (int) $request->session()->pull('signup.prefill.average_ticket_dollars', 0);
        $request->session()->forget('signup.prefill');
        $request->session()->forget('claimable.claim_page_id');

        if ($ticketDollars > 0) {
            $user->business->forceFill(['average_ticket_cents' => $ticketDollars * 100])->save();
        }

        // G6 demo attribution (Amendment 63, Section 8 C-1 /
        // Section 15.1): a signup completing inside the demo
        // attribution window fires demo_to_signup. The record
        // it lands on holds no phone and no shop name; the new
        // business starts clean of demo activity (C-5).
        $demoCookie = $request->cookie((string) config('demo.attribution_cookie'));

        if (is_string($demoCookie) && $demoCookie !== '') {
            $demoAttribution = \App\Models\DemoAttribution::query()
                ->where('token_hash', \App\Support\OpaqueToken::hash($demoCookie))
                ->where('expires_at', '>', now())
                ->first();

            if ($demoAttribution !== null && $demoAttribution->signup_business_id === null) {
                $demoAttribution->forceFill(['signup_business_id' => $user->business->id])->save();

                $liveDemo = app(\App\Services\Demo\DemoSessionManager::class)->fromRequest($request);
                \App\Models\DemoEvent::record(
                    $liveDemo !== null ? (string) ($liveDemo->data['session_key'] ?? 'attribution-'.$demoAttribution->id) : 'attribution-'.$demoAttribution->id,
                    'demo_to_signup',
                    null,
                    $demoAttribution->entry_point,
                );
            }
        }

        $request->session()->forget('demo.carryover_phone');

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.login_at', now()->timestamp);
        $request->session()->put('auth.rotated_at', now()->timestamp);

        AuditLog::write('owner.registered', $user);

        // P4 (OB-U1, pack Section 5.2): a new business enters
        // the onboarding front door, not the bare dashboard.
        // Login is unchanged: return visits land on the
        // dashboard, whose resume surfaces own re-entry.
        return redirect()->route('onboarding.welcome');
    }
}
