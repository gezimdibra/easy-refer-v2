<?php

namespace App\Http\Controllers;

use App\Models\ClaimablePage;
use App\Models\ClaimReview;
use App\Services\Claimable\ClaimableService;
use App\Services\Claimable\ClaimGateService;
use App\Support\ClaimableCopy;
use App\Support\OpaqueToken;
use App\Support\PhoneNumber;
use App\Support\QrImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The G1 claimable shop page (design pass §2 Variant A, §3-§5,
 * §8, §10). One token URL per shop; the page's state machine is
 * the §5 lifecycle and every string renders from ClaimableCopy in
 * exactly its §10.3 valid states. The preview poster's code is
 * INERT (H4): it resolves only to this page. The demo is a
 * separate, write-nothing context (H3/H4). The claim gate (§4)
 * runs before any account exists; the hand-off is the ordinary
 * signup with the snapshot in the existing session prefill.
 */
class ClaimableController extends Controller
{
    public function __construct(private ClaimableService $pages) {}

    private function page(string $token): ClaimablePage
    {
        $page = $this->pages->findByToken($token);
        abort_if($page === null, 404);

        // Time passing changes truth (§5 blast radius 5): an
        // openable page past its 60 days becomes P7 on read.
        if ($page->isOpenable() && $page->isExpiredByClock()) {
            $this->pages->expire($page);
            $page->refresh();
        }

        // §4/C32 (review item 1b): a pending-review page past its
        // 7-day lock frees itself on read — derive-at-request-time,
        // the same pattern as the expiry above; the hourly sweep
        // frees pages nobody reads.
        if ($page->status === ClaimablePage::STATUS_PENDING_REVIEW
            && $this->pages->expireReviewLock($page)) {
            $page->refresh();
        }

        return $page;
    }

    public function show(Request $request, string $token): View|Response
    {
        $page = $this->page($token);

        return match ($page->status) {
            ClaimablePage::STATUS_CLAIMED => view('claimable.tombstone', ['page' => $page]),
            ClaimablePage::STATUS_REMOVED => $this->removedView($request, $page),
            ClaimablePage::STATUS_EXPIRED, ClaimablePage::STATUS_KILLED => view('claimable.expired', ['page' => $page]),
            ClaimablePage::STATUS_PENDING_REVIEW => $this->pendingView($request, $page),
            default => $this->fullPage($request, $page),
        };
    }

    private function removedView(Request $request, ClaimablePage $page): View
    {
        // §8.4: a claimer whose claim died mid-flight sees C37 (the
        // true in-flight state), once; everyone else sees C35, and
        // the requester at the request moment also sees C36.
        $killed = $request->session()->pull('claimable.killed_claim.'.$page->id, false);
        $justRemoved = $request->session()->pull('claimable.just_removed.'.$page->id, false);

        return view('claimable.removed', [
            'page' => $page,
            'line' => $killed ? ClaimableCopy::c37() : ClaimableCopy::c35(),
            'confirmation' => $justRemoved ? ClaimableCopy::c36($page) : null,
        ]);
    }

    private function pendingView(Request $request, ClaimablePage $page): View
    {
        $mine = $request->session()->get('claimable.review_identity.'.$page->id);
        $review = $page->reviews()->where('status', 'pending')->latest()->first();
        $isRequester = $mine !== null && $review !== null && hash_equals($review->identity_hash, (string) $mine);

        return view('claimable.pending', ['page' => $page, 'isRequester' => $isRequester, 'token' => $request->route('token')]);
    }

    private function fullPage(Request $request, ClaimablePage $page): View
    {
        if (! $request->session()->get('claimable.opened.'.$page->id)) {
            $request->session()->put('claimable.opened.'.$page->id, true);
            $page->recordEvent('opened');
        }

        return view('claimable.show', ['page' => $page, 'token' => $request->route('token')]);
    }

    /** The preview poster (H3/H4): inert code, preview line on the artwork itself. */
    public function poster(string $token): View
    {
        $page = $this->page($token);
        abort_unless($page->isOpenable(), 404);

        return view('claimable.poster', ['page' => $page, 'token' => $token]);
    }

    /** The inert preview code: it encodes THIS page's URL, nothing else (H4). */
    public function qr(string $token): Response
    {
        $page = $this->page($token);
        abort_unless($page->isOpenable(), 404);

        return response(QrImage::svg(route('claimable.show', ['token' => $token])), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    /**
     * The demo context (H3/H4): the customer flow's screens wearing
     * the shop's name, labelled Demo on every screen. It writes
     * NOTHING — no memberships, referrals, rewards, or texts can be
     * created from it, by construction: these actions only render
     * and record the page's own funnel events (§9.2).
     */
    public function demo(Request $request, string $token): View
    {
        $page = $this->page($token);
        abort_unless($page->isOpenable(), 404);

        if (! $request->session()->get('claimable.demo.'.$page->id)) {
            $request->session()->put('claimable.demo.'.$page->id, true);
            $page->recordEvent('demo_started');
        }

        return view('claimable.demo', ['page' => $page, 'token' => $token]);
    }

    public function demoComplete(Request $request, string $token): RedirectResponse
    {
        $page = $this->page($token);
        abort_unless($page->isOpenable(), 404);

        if ($request->session()->pull('claimable.demo.'.$page->id)) {
            $page->recordEvent('demo_completed');
        }

        return redirect()->route('claimable.show', ['token' => $token]);
    }

    /** Claim tap (P3 begins): the gate screen, or the §4 fallback when no listed phone exists. */
    public function claimStart(Request $request, string $token): View|RedirectResponse
    {
        $page = $this->page($token);

        if ($page->status === ClaimablePage::STATUS_PENDING_REVIEW) {
            return redirect()->route('claimable.show', ['token' => $token]);
        }
        abort_unless($page->isOpenable(), 404);

        if (! $request->session()->get('claimable.started.'.$page->id)) {
            $request->session()->put('claimable.started.'.$page->id, true);
            $page->recordEvent('claim_started');
        }

        return view('claimable.gate', [
            'page' => $page,
            'token' => $token,
            'hasPhone' => $page->listed_phone_e164 !== null && $page->listed_phone_e164 !== '',
            'codeSent' => $request->session()->has('claimable.code_sent_at.'.$page->id),
            'error' => $request->session()->get('claimable.gate_error'),
        ]);
    }

    public function gateSend(Request $request, ClaimGateService $gate, string $token): RedirectResponse
    {
        $page = $this->page($token);
        abort_unless($page->isOpenable(), 404);
        $channel = $request->input('channel') === 'call' ? 'call' : 'sms';

        $outcome = $gate->sendCode($page, $channel);
        $request->session()->forget('claimable.gate_error');

        if ($outcome === 'started') {
            $request->session()->put('claimable.code_sent_at.'.$page->id, now()->timestamp);
        } elseif ($outcome === 'locked') {
            $request->session()->put('claimable.gate_error', ClaimableCopy::c25LockSends($gate->lockedUntil($page)));
        } else {
            $request->session()->put('claimable.gate_error', ClaimableCopy::c25SendFailed());
        }

        return redirect()->route('claimable.claim', ['token' => $token]);
    }

    public function gateCheck(Request $request, ClaimGateService $gate, string $token): RedirectResponse
    {
        $page = $this->page($token);

        // §8.4: the status is re-checked inside the claim's own
        // step — a correct code entered after a removal opens
        // nothing, and the claimer sees the true state (C37).
        if ($page->status === ClaimablePage::STATUS_REMOVED) {
            // §9.2 (review item 3): the blocked-by-removal count is
            // recorded at this C37 site so it is computable.
            $page->recordEvent('claim_blocked_removed', ['site' => 'gate_check']);
            $request->session()->put('claimable.killed_claim.'.$page->id, true);

            return redirect()->route('claimable.show', ['token' => $token]);
        }
        abort_unless($page->isOpenable(), 404);

        $sentAt = (int) $request->session()->get('claimable.code_sent_at.'.$page->id, 0);
        if ($sentAt > 0 && now()->timestamp - $sentAt > 600) {
            $request->session()->put('claimable.gate_error', ClaimableCopy::c25Expired());

            return redirect()->route('claimable.claim', ['token' => $token]);
        }

        $outcome = $gate->checkCode($page, (string) $request->input('code', ''));

        if ($outcome !== 'approved') {
            $request->session()->put('claimable.gate_error', match ($outcome) {
                'locked' => ClaimableCopy::c25LockWrongCodes($gate->lockedUntil($page)),
                default => ClaimableCopy::c25(),
            });

            return redirect()->route('claimable.claim', ['token' => $token]);
        }

        $this->openHandoff($request, $page);

        return redirect()->route('register');
    }

    /**
     * §4 fallback: no reachable listed phone. Creates a review row
     * and locks the page (P4) for 7 days. NO account is created.
     */
    public function reviewRequest(Request $request, string $token): RedirectResponse
    {
        $page = $this->page($token);
        abort_unless($page->isOpenable(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
        ]);

        $phoneE164 = PhoneNumber::normalizeToE164($data['phone']);
        $identity = ClaimReview::identityHash($data['name'], $phoneE164);

        // §4: a denied review locks that identity out of
        // re-requesting for 30 days.
        $denied = ClaimReview::query()
            ->where('identity_hash', $identity)
            ->where('status', 'denied')
            ->where('decided_at', '>', now()->subDays((int) config('claimable.denied_identity_lockout_days')))
            ->exists();
        abort_if($denied, 422);

        ClaimReview::create([
            'claimable_page_id' => $page->id,
            'kind' => ClaimReview::KIND_GATE_FALLBACK,
            'requester_name' => $data['name'],
            'contact_phone_e164' => $phoneE164,
            'identity_hash' => $identity,
            'status' => 'pending',
        ]);

        $page->forceFill(['status' => ClaimablePage::STATUS_PENDING_REVIEW])->save();
        $page->recordEvent('review_requested');
        $request->session()->put('claimable.review_identity.'.$page->id, $identity);

        return redirect()->route('claimable.show', ['token' => $token]);
    }

    /** §8.1: one tap, no form, no verification to hide. */
    public function remove(Request $request, string $token): RedirectResponse
    {
        $page = $this->page($token);

        if ($page->status === ClaimablePage::STATUS_CLAIMED) {
            // §8.4 claimed branch: there is no unclaimed page to
            // remove. The tombstone carries the contested path.
            return redirect()->route('claimable.show', ['token' => $token]);
        }

        if (in_array($page->status, [ClaimablePage::STATUS_EXPIRED, ClaimablePage::STATUS_KILLED], true)) {
            // §8.5 (review item 2a): expiry and liveness kills are
            // not removals and never suppress. The route itself —
            // not the absent C34 link — refuses the transition: no
            // state change, and a removal ask on an ended page is
            // an operator matter, not a suppression.
            return redirect()->route('claimable.show', ['token' => $token]);
        }

        $hadClaimSession = $request->session()->has('claimable.started.'.$page->id)
            || $request->session()->has('claimable.review_identity.'.$page->id);

        $this->pages->requestRemoval($page, 'page_footer');

        if ($hadClaimSession) {
            $request->session()->put('claimable.killed_claim.'.$page->id, true);
        }
        $request->session()->put('claimable.just_removed.'.$page->id, true);

        return redirect()->route('claimable.show', ['token' => $token]);
    }

    /** The contested-claim intake (G1-C41), from the tombstone (G1-C39). */
    public function contestedForm(string $token): View
    {
        $page = $this->page($token);
        abort_unless($page->status === ClaimablePage::STATUS_CLAIMED, 404);

        return view('claimable.contested', ['page' => $page, 'token' => $token]);
    }

    public function contestedStore(Request $request, string $token): RedirectResponse
    {
        $page = $this->page($token);
        abort_unless($page->status === ClaimablePage::STATUS_CLAIMED, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
        ]);

        $phoneE164 = PhoneNumber::normalizeToE164($data['phone']);

        ClaimReview::create([
            'claimable_page_id' => $page->id,
            'kind' => ClaimReview::KIND_CONTESTED,
            'requester_name' => $data['name'],
            'contact_phone_e164' => $phoneE164,
            'identity_hash' => ClaimReview::identityHash($data['name'], $phoneE164),
            'status' => 'pending',
        ]);
        $page->recordEvent('contested');

        return redirect()->route('claimable.show', ['token' => $token])
            ->with('claimable.contested_received', true);
    }

    /**
     * The approved-review hand-off (§4): single-use, 72 hours. The
     * operator approves in the register (ClaimReviewService); this
     * link is what the approved identity opens.
     */
    public function handoff(Request $request, string $token): RedirectResponse
    {
        $review = ClaimReview::query()
            ->where('handoff_token_hash', OpaqueToken::hash($token))
            ->where('status', 'approved')
            ->where('handoff_expires_at', '>', now())
            ->first();
        abort_if($review === null, 404);

        $page = $review->page;
        abort_unless($page->status === ClaimablePage::STATUS_PENDING_REVIEW, 404);

        // Single-use: the token dies as the hand-off opens.
        $review->forceFill(['handoff_token_hash' => null, 'status' => 'handed_off'])->save();
        $this->openHandoff($request, $page);

        return redirect()->route('register');
    }

    /**
     * §3.2 step 2: the snapshot enters the EXISTING signup session
     * prefill, bound to this browser session, and the claim is
     * armed. Nothing is created; the page flips to CLAIMED only at
     * the signup checkpoint POST (RegisteredUserController).
     */
    private function openHandoff(Request $request, ClaimablePage $page): void
    {
        $request->session()->put('claimable.claim_page_id', $page->id);
        $request->session()->put('signup.prefill', array_filter([
            'business_name' => $page->shop_name,
            'business_type' => $page->business_type,
            'claim_phone' => $page->listed_phone_e164,
            'claim_shop' => $page->shop_name,
            'claim_checked_date' => $page->snapshot_fetched_at?->format('F j, Y'),
        ], fn ($v) => $v !== null && $v !== ''));
    }
}
