<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\ActionCenterDismissal;
use App\Models\AuditLog;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Services\Billing\SettlementService;
use App\Services\Billing\VisitValuation;
use App\Services\Owner\ActionCenter;
use App\Support\ActionToast;
use App\Support\GoogleReviewUrl;
use App\Support\PhoneNumber;
use App\Support\SetupChecklist;
use App\Support\StatementWindow;
use App\Support\UsState;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The Settings surface (spec Section 5.5, design Section 6.3):
 * an index of separate pages, one job each: Business profile,
 * Rewards, Value, Reviews, Notifications, Referral limits,
 * Security (the existing account security page), and Account
 * (closure). Every write is validated with the same field rules
 * signup uses (AUTH-45: oversized input is rejected with a plain
 * message, never a 500), audited, and confirmed with the
 * inventory's saved line.
 */
class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $business = $user->business;

        // G6 (coherence Phase 2): "the checklist is hidden"
        // means the same thing here as on the dashboard and in
        // the Action Center — a stored dismissal whose
        // fingerprint still matches the checklist's current
        // composition.
        $storedFingerprint = ActionCenterDismissal::query()
            ->where('business_id', $business->id)
            ->where('item_key', 'setup_checklist')
            ->value('state_fingerprint');

        // SI-1/SI-2 (gap pack 05): each index row states what its
        // own settings page already computes, read once here from
        // the business record (plus the one card lookup the billing
        // page itself uses, PaymentMethod::defaultFor). No per-row
        // queries and no new state computations: every value below
        // is a column, a shipped helper, or that card predicate.
        // SI-3's query guard pins the count in SettingsGapsTest.
        $card = PaymentMethod::defaultFor($business);

        // SA-07 (Amendment 35): the billing row reads the card
        // through the same predicates the Billing page uses
        // (PaymentMethod::isExpired / expiresSoon), with the
        // index's own labels in the matching tones, so this
        // glance page can never be greener than the truth one
        // click away. Past due still owns the row first.
        $billingState = $business->billing_status === 'past_due'
            ? ['kind' => 'pill', 'tone' => 'pill-error', 'text' => 'Past due']
            : ($card === null
                ? ['kind' => 'pill', 'tone' => 'pill-warning', 'text' => 'No card']
                : ($card->isExpired()
                    ? ['kind' => 'pill', 'tone' => 'pill-error', 'text' => 'Card expired']
                    : ($card->expiresSoon()
                        ? ['kind' => 'pill', 'tone' => 'pill-warning', 'text' => 'Card expires soon']
                        : ['kind' => 'pill', 'tone' => 'pill-success', 'text' => 'Card on file'])));

        $indexStates = [
            // ORS-02/A4 (OR-1): the index line names the one
            // reward; a SPLIT business appends the branch marker
            // so the line never reads as if the friend's value
            // were the whole offer.
            'settings.rewards' => filled($business->friend_reward_text)
                ? ['kind' => 'truncated', 'text' => \Illuminate\Support\Str::limit((string) $business->friend_reward_text, 32).($business->rewardsAreSplit() ? ' · a separate one for referrers' : '')]
                : ['kind' => 'pill', 'tone' => 'pill-warning', 'text' => 'Not set yet'],
            // P1 SPEND-DEFAULT: the quiet figure is the owner's
            // own only when one is set (hasTypicalSpend: NULL
            // or a stored 0 is unset, the RS2-3 rule); a zero
            // never renders as "$0.00" beside the row.
            'settings.value' => $business->hasTypicalSpend()
                ? ['kind' => 'quiet', 'text' => '$'.number_format($business->average_ticket_cents / 100, 2)]
                : null,
            // ON-SET-3 sweep: the review link is optional in
            // validation, so its unset pill carries the same
            // "(optional)" honesty as the Reviews page itself
            // (ON-SET-1) — never a bare "Not set yet" owed tone.
            'settings.reviews' => filled($business->google_review_url)
                ? ['kind' => 'pill', 'tone' => 'pill-success', 'text' => 'Link set']
                : ['kind' => 'pill', 'tone' => 'pill-neutral', 'text' => 'Not set (optional)'],
            'settings.limits' => ['kind' => 'quiet', 'text' => "{$business->daily_referral_cap} a day · {$business->monthly_referral_cap} a month"],
            // Amendment 38 (Train A3-1): the index row names the
            // one real switch; the weekly state died with the
            // weekly-digest toggle it described (no sender exists).
            'settings.notifications' => ['kind' => 'quiet', 'text' => $business->owner_text_notifications ? 'First-visit text on' : 'First-visit text off'],
            'security' => $user->hasTwoFactorEnabled()
                ? ['kind' => 'pill', 'tone' => 'pill-success', 'text' => 'Two-factor on']
                : ['kind' => 'pill', 'tone' => 'pill-neutral', 'text' => 'Two-factor off'],
            'settings.billing' => $billingState,
        ];

        return view('owner.settings.index', [
            'business' => $business,
            'indexStates' => $indexStates,
            'checklistDismissed' => $storedFingerprint !== null
                && $storedFingerprint === ActionCenter::setupFingerprint(SetupChecklist::for($business, $user)),
        ]);
    }

    public function profile(Request $request): View
    {
        return view('owner.settings.profile', [
            'business' => $request->user()->business,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        // Same state handling as signup: a US state arrives as a
        // code from the form's select, and a full name typed or
        // posted directly ("New York") normalizes to the stored
        // 2-letter code before validation and saving.
        $isUs = strtoupper(trim((string) $request->input('country', ''))) === 'US';

        if ($isUs && ($stateCode = UsState::normalize($request->input('state', ''))) !== null) {
            $request->merge(['state' => $stateCode]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', Rule::in(Business::TYPES)],
            'contact_email' => ['required', 'string', 'email', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, \Closure $fail) {
                if (PhoneNumber::normalizeToE164((string) $value) === null) {
                    $fail('Enter a valid phone number.');
                }
            }],
            'street' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail) use ($isUs) {
                if ($isUs && UsState::normalize($value) === null) {
                    // The list is real: the US state control on
                    // this form is a select of exactly these codes.
                    $fail('Select the state from the list.');
                }
            }],
            'postal_code' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', Rule::in(config('countries.codes'))],
        ], [
            'name.required' => 'Enter your business name.',
            'contact_email.required' => 'Enter a contact email.',
            'contact_email.email' => 'Enter a valid email address.',
            'street.required' => 'Enter your street address.',
            'city.required' => 'Enter your city.',
            'state.required' => $isUs ? 'Choose your state.' : 'Enter your state or province.',
            'postal_code.required' => 'Enter your postal code.',
            'max' => 'That entry is too long. Shorten it and try again.',
        ]);

        $business = $request->user()->business;

        $business->forceFill([
            'name' => $data['name'],
            'business_type' => $data['business_type'] ?? null,
            'contact_email' => $data['contact_email'],
            'contact_phone_e164' => PhoneNumber::normalizeToE164($data['contact_phone']),
            'street' => $data['street'],
            'city' => $data['city'],
            'state' => $data['state'],
            'postal_code' => $data['postal_code'],
            'country' => $data['country'],
        ])->save();

        AuditLog::write('business.profile_updated', $request->user(), [], $business);

        return back()->with('status', 'Business profile saved.');
    }

    public function rewards(Request $request): View
    {
        // A starter pack chosen anywhere lands here as a prefill
        // (spec Section 5.5, design 6.4.1 item 3): the pack's ONE
        // reward sits in the reward field, editable, and nothing
        // is saved until this form is submitted. A pack is a
        // unified offer, so a pack prefill also turns the form's
        // split toggle OFF (§4.1 pin). The legacy pack_friend
        // query key is still read as the reward for links minted
        // before OR-1; pack_referrer no longer exists.
        $prefill = [];
        $friend = $request->query('pack_reward', $request->query('pack_friend'));

        if (is_string($friend) && $friend !== '' && strlen($friend) <= 120) {
            $prefill['friend'] = $friend;
            $prefill['pack'] = true;
        }

        return view('owner.settings.rewards', [
            'business' => $request->user()->business,
            'packs' => \App\Support\StarterPacks::forType($request->user()->business->business_type),
            'prefill' => $prefill,
            'vestingMin' => (int) config('referrals.vesting_days_min'),
            'vestingMax' => (int) config('referrals.vesting_days_max'),
        ]);
    }

    /**
     * The rewards page write path. The text pair follows exactly
     * the RewardTextController rules (required friend text,
     * optional referrer text, 120 characters each) and writes the
     * same audit action, so the starter-pack path and this page
     * can never drift apart in what they accept.
     */
    public function updateRewards(Request $request): RedirectResponse
    {
        // The vesting bounds are the platform's OD-3 contract,
        // pulled from config at validation time (the updateLimits
        // pattern), and interpolated into rules and messages so a
        // future bounds change cannot strand the copy (GAP 2).
        $vestingMin = (int) config('referrals.vesting_days_min');
        $vestingMax = (int) config('referrals.vesting_days_max');

        $data = $request->validate([
            'friend_reward_text' => ['required', 'string', 'max:120'],
            'reward_split' => ['nullable', 'boolean'],
            'referrer_reward_text' => ['nullable', 'string', 'max:120'],
            'redemption_window_days' => ['required', 'integer', 'min:1', 'max:365'],
            'reward_vesting_days' => ['required', 'integer', "min:{$vestingMin}", "max:{$vestingMax}"],
        ], [
            'friend_reward_text.required' => 'Write the friend reward.',
            'max' => 'That entry is too long. Shorten it and try again.',
            'redemption_window_days.min' => 'Enter a whole number of days, at least 1.',
            'redemption_window_days.max' => 'Enter a whole number of days, at most 365.',
            'reward_vesting_days.min' => "Enter a whole number of days, at least {$vestingMin}.",
            'reward_vesting_days.max' => "Enter a whole number of days, at most {$vestingMax}.",
            'reward_vesting_days.integer' => 'Enter a whole number of days.',
            'reward_vesting_days.required' => 'Enter a whole number of days.',
        ]);

        // OR-1 §4.2: split ON with a blank referrer field is a
        // validation error naming the field — NEVER a silent
        // fallback save (a stored split-with-blank would make
        // the toggle's state a lie the preview would repeat).
        $split = $request->boolean('reward_split');

        if ($split && trim((string) ($data['referrer_reward_text'] ?? '')) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'referrer_reward_text' => "Write the referrer reward, or turn off 'Give the referrer something different'.",
            ]);
        }

        $business = $request->user()->business;

        // RS-14: the reward TEXTS are the reversible part of
        // this save, so the toast's Undo snapshots the raw
        // prior pair; the windows and vesting in the same save
        // are settings, not texts, and an undo leaves them be.
        // OR-1 §2.4/E5: the snapshot also carries the split
        // FLAG — a pure toggle flip is a configuration change,
        // and an undo must restore a split whole, not two texts
        // under a unified flag.
        $priorTexts = [
            'friend_reward_text' => $business->getRawOriginal('friend_reward_text'),
            'referrer_reward_text' => $business->getRawOriginal('referrer_reward_text'),
            'reward_split' => $business->getRawOriginal('reward_split'),
        ];

        $business->setRewardConfiguration(
            (string) $data['friend_reward_text'],
            $split,
            $split ? ($data['referrer_reward_text'] ?? null) : null,
        );

        $business->forceFill([
            'redemption_window_days' => (int) $data['redemption_window_days'],
            'reward_vesting_days' => (int) $data['reward_vesting_days'],
        ])->save();

        AuditLog::write('business.reward_texts_updated', $request->user(), [
            'redemption_window_days' => (int) $data['redemption_window_days'],
            'reward_vesting_days' => (int) $data['reward_vesting_days'],
        ], $business);

        // W3 (Amendment 38, Train A3-8): the staleness reminder
        // fires only when this save actually changed a reward
        // text AND a poster download exists for the business;
        // it can therefore never render stale itself.
        $textsChanged = (string) ($priorTexts['friend_reward_text'] ?? '') !== (string) $data['friend_reward_text']
            || (string) ($priorTexts['referrer_reward_text'] ?? '') !== (string) ($split ? ($data['referrer_reward_text'] ?? '') : '')
            || (bool) ($priorTexts['reward_split'] ?? false) !== $split;
        $hasDownload = \App\Models\ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'poster_downloaded')
            ->exists();
        $savedMessage = ($textsChanged && $hasDownload)
            ? 'Rewards saved. Your printed posters still show the old rewards. Print fresh posters from your QR page.'
            : 'Rewards saved.';

        ActionToast::undoable(
            $request,
            $savedMessage,
            'reward_texts',
            $priorTexts,
            'Your reward changes were undone.',
            'settings.rewards',
        );

        return back()->with('status', $savedMessage);
    }

    public function value(Request $request): View
    {
        return view('owner.settings.value', [
            'business' => $request->user()->business,
        ]);
    }

    public function updateValue(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'average_ticket' => ['required', 'numeric', 'min:0', 'max:100000'],
        ], [
            'average_ticket.required' => 'Enter your typical spend per visit.',
            'average_ticket.numeric' => 'Enter a dollar amount, like 45 or 45.50.',
            'average_ticket.min' => 'Enter an amount of 0 or more.',
            'average_ticket.max' => 'Enter an amount under $100,000.',
        ]);

        $business = $request->user()->business;

        $business->forceFill([
            'average_ticket_cents' => (int) round(((float) $data['average_ticket']) * 100),
        ])->save();

        AuditLog::write('business.average_ticket_updated', $request->user(), [], $business);

        return back()->with('status', 'Value saved.');
    }

    public function reviews(Request $request): View
    {
        $business = $request->user()->business;

        return view('owner.settings.reviews', [
            'business' => $business,
            // ID-05 (REVIEW-03): trailing-30-day ask activity,
            // counts of OUR events only.
            'reviewAskCounts' => \App\Services\Flow\ReviewAsk::trailingCounts($business),
            'reviewAskCooldownDays' => (int) config('referrals.review_ask_cooldown_days', 90),
        ]);
    }

    public function updateReviews(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'google_review_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $url = isset($data['google_review_url']) ? trim((string) $data['google_review_url']) : '';

        // Store the canonical rebuild, never the raw paste, so
        // the value rendered on receipts is exactly the URL that
        // passed the allowlist (security verifier F-01).
        $canonical = $url === '' ? null : GoogleReviewUrl::canonicalize($url);

        if ($url !== '' && $canonical === null) {
            return back()
                ->withInput()
                ->withErrors(['google_review_url' => 'That link does not look like a Google review link. Paste the link Google gives you for reviews.']);
        }

        $business = $request->user()->business;

        $business->forceFill(['google_review_url' => $canonical])->save();

        AuditLog::write('business.review_url_updated', $request->user(), [], $business);

        return back()->with('status', 'Review link saved.');
    }

    public function notifications(Request $request): View
    {
        return view('owner.settings.notifications', [
            'business' => $request->user()->business,
        ]);
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        // The two opt-out columns store the negative (SET-06).
        // Amendment 38 (Train A3-1): their toggles are removed
        // (no digest or nudge sender exists), so the columns are
        // inert; the storage lines stay untouched so a stale
        // form post cannot error, and a future sender revives
        // the flags with their meaning intact.
        $business->forceFill([
            'owner_text_notifications' => $request->boolean('owner_text_notifications'),
            'weekly_digest_opt_out' => ! $request->boolean('weekly_summary'),
            'nudge_opt_out' => ! $request->boolean('nudges'),
            // M-5 (design pass Section 7.5): the setup-reminder
            // turn-off is its OWN column, submitted explicitly
            // by the setup_reminders checkbox in the form below
            // (the negative storage of SET-06, like its
            // siblings). It is read only by the drip sender;
            // neither inert column above is ever read as it,
            // and no other form path writes it.
            'setup_reminders_opt_out' => ! $request->boolean('setup_reminders'),
        ])->save();

        AuditLog::write('business.notifications_updated', $request->user(), [], $business);

        return back()->with('status', 'Notification settings saved.');
    }

    public function limits(Request $request): View
    {
        // SL-1 (gap pack 05): the bounds and defaults the view
        // states in words come from the same config keys
        // updateLimits() validates against, so the rendered range
        // and the validation range share one source.
        return view('owner.settings.limits', [
            'business' => $request->user()->business,
            'dailyMin' => (int) config('referrals.velocity_daily_min'),
            'dailyMax' => (int) config('referrals.velocity_daily_platform_max'),
            'dailyDefault' => (int) config('referrals.velocity_daily_default'),
            'monthlyMin' => (int) config('referrals.velocity_monthly_min'),
            'monthlyMax' => (int) config('referrals.velocity_monthly_platform_max'),
            'monthlyDefault' => (int) config('referrals.velocity_monthly_default'),
        ]);
    }

    public function updateLimits(Request $request): RedirectResponse
    {
        $dailyMin = (int) config('referrals.velocity_daily_min');
        $dailyMax = (int) config('referrals.velocity_daily_platform_max');
        $monthlyMin = (int) config('referrals.velocity_monthly_min');
        $monthlyMax = (int) config('referrals.velocity_monthly_platform_max');

        $data = $request->validate([
            'daily_referral_cap' => ['required', 'integer', "min:{$dailyMin}", "max:{$dailyMax}"],
            'monthly_referral_cap' => ['required', 'integer', "min:{$monthlyMin}", "max:{$monthlyMax}"],
        ], [
            'daily_referral_cap.min' => "Enter a whole number between {$dailyMin} and {$dailyMax}.",
            'daily_referral_cap.max' => "Enter a whole number between {$dailyMin} and {$dailyMax}.",
            'monthly_referral_cap.min' => "Enter a whole number between {$monthlyMin} and {$monthlyMax}.",
            'monthly_referral_cap.max' => "Enter a whole number between {$monthlyMin} and {$monthlyMax}.",
            'integer' => 'Enter a whole number.',
            'required' => 'Enter a whole number.',
        ]);

        $business = $request->user()->business;

        $business->forceFill([
            'daily_referral_cap' => (int) $data['daily_referral_cap'],
            'monthly_referral_cap' => (int) $data['monthly_referral_cap'],
        ])->save();

        AuditLog::write('business.velocity_limits_updated', $request->user(), [], $business);

        return back()->with('status', 'Referral limits saved.');
    }

    public function account(Request $request, SettlementService $settlement): View
    {
        $business = $request->user()->business;

        // M4-E12-01: the same set-level transition trio the
        // Rewards page and the dashboard run before counting
        // (vesting -> closure expiry -> time expiry, in their
        // order), so every count and flag below — the [E-12]
        // outstanding count included — reads post-transition
        // state and agrees with the Rewards page by construction.
        // Without it this surface counted stored state only and
        // could overstate outstanding rewards (a time-expired
        // available reward, or a both-due vesting one, still
        // counted until some other surface ran the transitions).
        \App\Models\Reward::applyVestingForBusiness($business);
        \App\Models\Reward::applyClosureExpiryForBusiness($business);
        \App\Models\Reward::applyTimeExpiryForBusiness($business);

        // FE-CANCEL-01 (design 1.1 + section 7): the End panel
        // renders the owner's own live figures from the same
        // settlement walk the charger uses, and rendering it
        // with the flow available is the funnel's one recorded
        // start (business.end_panel_viewed).
        if (! $business->isClosed()) {
            AuditLog::write('business.end_panel_viewed', $request->user(), [], $business);
        }

        return view('owner.settings.account', [
            'business' => $business,
            'user' => $request->user(),
            'forecastPreview' => $settlement->forecastPreview($business),
            'pendingReferralsCount' => \App\Support\DemoExclusion::referrals(\App\Models\Referral::query())
                ->where('business_id', $business->id)
                ->where('state', \App\Models\Referral::STATE_PENDING)
                ->count(),
            'vestingRewardsCount' => \App\Support\DemoExclusion::rewards(\App\Models\Reward::query())
                ->where('business_id', $business->id)
                ->where('state', \App\Models\Reward::STATE_PENDING_VESTING)
                ->count(),
            // E-12 (Amendment 70; the design 1.1 line the M-3
            // build dropped): rewards still outstanding, counted
            // from the same states the Rewards page buckets — a
            // reward is outstanding while pending_vesting or
            // available, held or not (the page's "On its way" +
            // "Ready to claim" buckets plus held rewards in
            // those live states); claimed, denied, and expired
            // rewards are terminal and never counted. The total
            // is vesting-transition invariant (both live states
            // count), but M-4's time expiry is not invariant —
            // so the trio at the top of this method runs first
            // (M4-E12-01) and the count reads post-transition
            // state, exactly like the Rewards page.
            'outstandingRewardsCount' => \App\Support\DemoExclusion::rewards(\App\Models\Reward::query())
                ->where('business_id', $business->id)
                ->whereIn('state', [\App\Models\Reward::STATE_PENDING_VESTING, \App\Models\Reward::STATE_AVAILABLE])
                ->count(),
            // M4-E12-01 ([M4-E12-01] addendum to Amendment 71):
            // the [E-12] duration half. The unbranched "good
            // until 30 days after you end" is false for a counted
            // reward whose own stamped use-by date falls inside
            // the prospective closure window (ended now, the
            // window ends 30 days out — the same window
            // [M4-ACCT-03] uses). Same population as the count
            // (live states, held or not), computed after the
            // transitions above; strict <, so a date exactly at
            // the window end leaves the original sentence true.
            'outstandingRewardOwnDateInsideWindow' => \App\Support\DemoExclusion::rewards(\App\Models\Reward::query())
                ->where('business_id', $business->id)
                ->whereIn('state', [\App\Models\Reward::STATE_PENDING_VESTING, \App\Models\Reward::STATE_AVAILABLE])
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now()->addDays(30))
                ->exists(),
            'allowanceRemaining' => $business->allowanceLeft(),
            'pastDueEpisode' => $business->pastDueEpisode(),
            // Train A2 (F-06, ruling C-1): the close screen names
            // the held queue while it exists, with the date the
            // wind-down would end if the owner closed now.
            'heldRewardsCount' => \App\Support\DemoExclusion::rewards(\App\Models\Reward::query())
                ->where('business_id', $business->id)
                ->where('hold', true)
                ->whereIn('state', [\App\Models\Reward::STATE_PENDING_VESTING, \App\Models\Reward::STATE_AVAILABLE])
                ->count(),
            // Two-key note 2 (r41 re-check, Amendment 38): the
            // held count must cover held REFERRALS too; counting
            // rewards only under-reported the queue by omission
            // when a held referral had no held reward.
            'heldReferralsCount' => \App\Support\DemoExclusion::referrals(\App\Models\Referral::query())
                ->where('business_id', $business->id)
                ->where('hold', true)
                ->count(),
            'heldClaimableUntil' => $business->localTime(now()->addDays(30))->format('M j, Y'),
            // M-4 D2 fix (Amendment 71 addendum, [M4-ACCT-03]):
            // the [E-HELD] tail's unconditional "until 30 days
            // after you end" is true only for a held reward whose
            // own use-by date does not fall inside the prospective
            // closure window (ended now, the window ends 30 days
            // out). A held reward already carrying an earlier
            // stamped date branches the tail, exactly as the four
            // Section 3.1 sites branch; a dateless or later-dated
            // held reward leaves the original sentence true for
            // every reward the line covers, so it renders as-is.
            'heldRewardOwnDateInsideWindow' => \App\Support\DemoExclusion::rewards(\App\Models\Reward::query())
                ->where('business_id', $business->id)
                ->where('hold', true)
                ->whereIn('state', [\App\Models\Reward::STATE_PENDING_VESTING, \App\Models\Reward::STATE_AVAILABLE])
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now()->addDays(30))
                ->exists(),
            // M-4 D2 fix, same-view sibling ([M4-ACCT-04]): the
            // closed in-window line [E-08] names the exact
            // closed_at + 30 days date unconditionally, which is
            // false for a live reward whose own use-by date falls
            // before that date. Same conditional branch against
            // the ACTUAL window end; past the window [E-08b]
            // needs none (closure expiry has ended claimability
            // for every live reward, held or not).
            'closedRewardOwnDateInsideWindow' => $business->isClosed() && $business->closed_at !== null && ! $business->closureWindowEnded()
                ? \App\Support\DemoExclusion::rewards(\App\Models\Reward::query())
                    ->where('business_id', $business->id)
                    ->whereIn('state', [\App\Models\Reward::STATE_PENDING_VESTING, \App\Models\Reward::STATE_AVAILABLE])
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', $business->closed_at->copy()->addDays(30))
                    ->exists()
                : false,
        ]);
    }

    /**
     * The billing page (billing UI spec Sections 1 and 2, build
     * unit BU-2): one page assembling Blocks A to E from the
     * bindings the spec names, so every figure agrees with the
     * dashboard and the batch by construction. Assembling the
     * page persists nothing: the this-month figures come from
     * VisitValuation::forMonth, and the accrued figure from
     * SettlementService's non-persisting preview. The past-due
     * banner markup ships keyed to billing_status; the status
     * itself is only ever set by the webhook lane (BU-8), so
     * until then the banner is inert by construction. Charge
     * history (Block E) is completed by BU-6.
     */
    public function billing(Request $request, VisitValuation $valuation, SettlementService $settlement): View
    {
        $business = $request->user()->business;
        $plan = $business->currentPlan ?? Plan::launch();
        $card = PaymentMethod::defaultFor($business);

        $pendingCard = PaymentMethod::query()
            ->where('business_id', $business->id)
            ->where('state', PaymentMethod::STATE_PENDING)
            ->latest('id')
            ->first();

        // SV-02 / SA-07: the card state comes from the model's
        // one definition, the same predicates the Settings
        // index row reads, so the two pages cannot disagree.
        $cardExpired = $card !== null && $card->isExpired();
        $cardExpiresSoon = $card !== null && $card->expiresSoon();

        $records = BillingRecord::query()
            ->where('business_id', $business->id)
            ->where('kind', BillingRecord::KIND_PER_REFERRAL_BATCH)
            ->where('state', '!=', BillingRecord::STATE_SUPERSEDED)
            ->with(['paymentMethod', 'refunds'])
            ->latest('id')
            ->get();

        return view('owner.settings.billing', [
            'business' => $business,
            'plan' => $plan,
            'card' => $card,
            'cardExpired' => $cardExpired,
            'cardExpiresSoon' => $cardExpiresSoon,
            'pendingCard' => $pendingCard,
            'month' => $valuation->forMonth($business, now()),
            'freeTotal' => (int) config('billing.free_visits_at_signup'),
            // Money fix train (audit 4.3): the This month block
            // scopes its built-up figure to the current month,
            // the same scope as its forMonth counts; the
            // all-months preview rides separately for the
            // forecast line and the earlier-months remainder.
            'accrued' => $settlement->accruedPreviewForMonth($business, now()),
            'accruedAll' => $settlement->accruedPreview($business),
            // Train A2: the forecast line renders the gated
            // forecast preview (visits + texts, charge expected
            // only with a card and a total at the minimum); the
            // month text figure extends BILL-06's carry-forward
            // condition to the combined total.
            'forecastPreview' => \App\Support\BillingForecast::gatedPreview(
                $business,
                null,
                $card !== null,
                ($textFigures = $settlement->textFigures($business, now()))['all'],
            ),
            'monthTextCents' => $textFigures['month'],
            // FE-CANCEL-01 (E-11): the closed Billing page
            // branches on the same reads the charger uses: a
            // pending final record (confirming), an unclaimed
            // balance (still owed), or neither (nothing more
            // will be charged).
            'closedPendingCents' => (int) BillingRecord::query()
                ->where('business_id', $business->id)
                ->where('state', BillingRecord::STATE_PENDING)
                ->sum('amount_cents'),
            'closedOwedCents' => $settlement->forecastPreview($business)['total_cents'],
            'minimumChargeCents' => (int) config('billing.minimum_charge_cents'),
            'chargesEnabled' => (bool) config('billing.charges_enabled'),
            'records' => $records,
            // Train 3.5: each history row names its window in
            // business-local terms, from the same helper the
            // statement page renders.
            'recordWindows' => StatementWindow::forRecords($records, $business),
        ]);
    }

    /**
     * The per-charge statement (billing UI spec Section 2,
     * Block F; build unit BU-6): one page per succeeded record,
     * the monthly value statement. Counts come from the
     * record's own events (the persisted coverage ledger), the
     * amount is the record's exact figure, and the value line
     * is VisitValuation::forMonth over the record's period,
     * labeled an estimate; the two never share a line. A
     * record that is not this business's, or never succeeded,
     * has no statement: 404, the same answer a stranger gets.
     */
    public function statement(Request $request, BillingRecord $record, VisitValuation $valuation): View
    {
        $business = $request->user()->business;

        if ($record->business_id !== $business->id
            || ! in_array($record->kind, [BillingRecord::KIND_PER_REFERRAL_BATCH, BillingRecord::KIND_CAMPAIGN_METERING], true)
            || $record->state !== BillingRecord::STATE_SUCCEEDED) {
            abort(404);
        }

        $counts = $record->events()
            ->selectRaw('coverage, COUNT(*) as total')
            ->groupBy('coverage')
            ->pluck('total', 'coverage');

        // STMT-02's Text campaigns line: the recipient rows this
        // record claimed, on a batch and on a campaign-metering
        // record alike.
        $textCampaignsCents = (int) \App\Models\SmsCampaignRecipient::query()
            ->where('billing_record_id', $record->id)
            ->sum('amount_cents');

        // BS-2 (gap pack 04): the receipt's visits row carries
        // its amount contribution, the sum over the record's
        // own claimed billable rows. Presentation only: the
        // charge stays the record amount, and this figure plus
        // the claimed text cents visibly sum to it because the
        // charger built the record amount from these same rows.
        $chargedCents = (int) $record->events()
            ->where('coverage', 'billable')
            ->sum('amount_cents');

        $estimate = $valuation->forMonth($business, Carbon::parse($record->period_start));

        return view('owner.settings.billing-statement', [
            'business' => $business,
            'record' => $record,
            // Train 3.5: the window is named in business-local
            // terms and labeled when it spans two months; the
            // record and its accounting do not move.
            'statementWindow' => StatementWindow::forRecord($record, $business),
            'charged' => (int) ($counts['billable'] ?? 0),
            'freeUsed' => (int) ($counts['free_credit'] ?? 0),
            'overCap' => (int) ($counts['over_cap'] ?? 0),
            'chargedCents' => $chargedCents,
            'textCampaignsCents' => $textCampaignsCents,
            // BS-3: a campaign-metering record claimed no visit
            // rows; its statement leads with the texts row.
            'campaignOnly' => $record->kind === BillingRecord::KIND_CAMPAIGN_METERING,
            'estimatedSalesCents' => $estimate['estimated_sales_cents'],
        ]);
    }

    /**
     * Show the setup checklist on the Overview again (spec
     * Section 5.8: dismissed state is resettable from Settings).
     *
     * Phase 2 (G6): the reset clears the ONE dismissal domain —
     * the Action Center dismissal row for the setup item (which
     * restores the dashboard card and the Action Center row
     * together) — plus the legacy checklist_dismissed_at column
     * for any pre-unification dismissal still recorded there.
     */
    public function resetChecklist(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        ActionCenterDismissal::query()
            ->where('business_id', $business->id)
            ->where('item_key', 'setup_checklist')
            ->delete();

        $business->forceFill(['checklist_dismissed_at' => null])->save();

        AuditLog::write('business.checklist_reset', $request->user(), [], $business);

        return back()->with('status', 'Checklist is showing on your Overview.');
    }

    /**
     * Account closure (spec Section 5.5, S3-15/EC-42). Step-up
     * verified at the route. The consequences run in one
     * transaction: the business closes, pending referrals void
     * (each with its timeline event), billing state is left for
     * the final reconciliation in the billing sprint, and the
     * whole action is audited. Minted rewards are untouched: they
     * stay claimable through the 30-day wind-down (DASH-13).
     */
    public function closeAccount(Request $request, SettlementService $settlement): RedirectResponse
    {
        $request->validate([
            'confirm' => ['accepted'],
            // FE-CANCEL-01 (E-REASON): the reason is optional,
            // one chip from the named list, never a gate.
            'end_reason' => ['nullable', 'string', Rule::in(['closing_shop', 'too_quiet', 'not_worth_it', 'switching', 'other'])],
        ], [
            'confirm.accepted' => 'Check the box to confirm you understand what ending your program does.',
        ]);

        $user = $request->user();
        $business = $user->business;

        if ($business->isClosed()) {
            return redirect()->route('settings.account');
        }

        // FE-CANCEL-01 (1.2/1.3): the final-bill snapshot is the
        // settlement walk's own forecast, taken at the tap. The
        // walk is persisted first (the charger's own first step)
        // so the snapshot and the coming attempt read the same
        // settled coverage, allowance-once included.
        $settlement->settleAndPersist($business);
        $forecast = $settlement->forecastPreview($business->fresh() ?? $business);
        $snapshot = [
            'owed_cents' => (int) $forecast['total_cents'],
            'billable_events' => (int) $forecast['billable_events'],
            'text_cents' => (int) $forecast['text_cents'],
            'visit_cents' => (int) $forecast['visit_cents'],
        ];
        $reason = $request->input('end_reason');

        DB::transaction(function () use ($business, $snapshot, $reason) {
            $business->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
            ])->save();

            Referral::query()
                ->where('business_id', $business->id)
                ->where('state', Referral::STATE_PENDING)
                ->each(function (Referral $referral) use ($business) {
                    $referral->forceFill(['state' => Referral::STATE_VOIDED])->save();

                    ReferralEvent::record(
                        'referral_voided',
                        'owner',
                        $business,
                        $referral,
                        null,
                        $referral->referrerMembership,
                        ['reason' => 'account_closed'],
                    );
                });
        });

        AuditLog::write('business.closed', $user, array_filter([
            'owed_cents' => $snapshot['owed_cents'],
            'billable_events' => $snapshot['billable_events'],
            'text_cents' => $snapshot['text_cents'],
            'visit_cents' => $snapshot['visit_cents'],
            'end_reason' => $reason,
        ], fn ($value) => $value !== null), $business);

        // The final bill is attempted after commit, in a queued
        // job: ending never waits on payment (design 1.2).
        \App\Jobs\RunFinalBill::dispatch($business->id, $snapshot);

        return redirect()->route('dashboard')->with('status', 'Your program has ended.');
    }
}
