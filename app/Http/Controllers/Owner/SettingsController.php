<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Services\Billing\SettlementService;
use App\Services\Billing\VisitValuation;
use App\Support\GoogleReviewUrl;
use App\Support\PhoneNumber;
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
        return view('owner.settings.index', [
            'business' => $request->user()->business,
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
            'state' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', Rule::in(config('countries.codes'))],
        ], [
            'name.required' => 'Enter your business name.',
            'contact_email.required' => 'Enter a contact email.',
            'contact_email.email' => 'Enter a valid email address.',
            'street.required' => 'Enter your street address.',
            'city.required' => 'Enter your city.',
            'state.required' => 'Enter your state or province.',
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
        // (spec Section 5.5, design 6.4.1 item 3): the pair sits
        // in the fields, editable, and nothing is saved until
        // this form is submitted.
        $prefill = [];
        $friend = $request->query('pack_friend');
        $referrer = $request->query('pack_referrer');

        if (is_string($friend) && $friend !== '' && strlen($friend) <= 120) {
            $prefill['friend'] = $friend;
        }

        if (is_string($referrer) && strlen($referrer) <= 120) {
            $prefill['referrer'] = $referrer;
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

        $business = $request->user()->business;

        $business->forceFill([
            'friend_reward_text' => $data['friend_reward_text'],
            'referrer_reward_text' => $data['referrer_reward_text'] ?? null,
            'redemption_window_days' => (int) $data['redemption_window_days'],
            'reward_vesting_days' => (int) $data['reward_vesting_days'],
        ])->save();

        AuditLog::write('business.reward_texts_updated', $request->user(), [
            'redemption_window_days' => (int) $data['redemption_window_days'],
            'reward_vesting_days' => (int) $data['reward_vesting_days'],
        ], $business);

        return back()->with('status', 'Rewards saved.');
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
            'average_ticket.required' => 'Enter your average visit value.',
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
        return view('owner.settings.reviews', [
            'business' => $request->user()->business,
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

        // The two opt-out columns store the negative; the form
        // presents all three as plain on/off switches (SET-06), so
        // the mapping inverts here and nowhere else.
        $business->forceFill([
            'owner_text_notifications' => $request->boolean('owner_text_notifications'),
            'weekly_digest_opt_out' => ! $request->boolean('weekly_summary'),
            'nudge_opt_out' => ! $request->boolean('nudges'),
        ])->save();

        AuditLog::write('business.notifications_updated', $request->user(), [], $business);

        return back()->with('status', 'Notification settings saved.');
    }

    public function limits(Request $request): View
    {
        return view('owner.settings.limits', [
            'business' => $request->user()->business,
            'dailyMin' => (int) config('referrals.velocity_daily_min'),
            'dailyMax' => (int) config('referrals.velocity_daily_platform_max'),
            'monthlyMin' => (int) config('referrals.velocity_monthly_min'),
            'monthlyMax' => (int) config('referrals.velocity_monthly_platform_max'),
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

    public function account(Request $request): View
    {
        return view('owner.settings.account', [
            'business' => $request->user()->business,
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

        $cardExpired = false;
        if ($card !== null) {
            $cardExpired = $card->exp_year < now()->year
                || ($card->exp_year === now()->year && $card->exp_month < now()->month);
        }

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
            'pendingCard' => $pendingCard,
            'month' => $valuation->forMonth($business, now()),
            'freeTotal' => (int) config('billing.free_visits_at_signup'),
            'accrued' => $settlement->accruedPreview($business),
            'minimumChargeCents' => (int) config('billing.minimum_charge_cents'),
            'chargesEnabled' => (bool) config('billing.charges_enabled'),
            'records' => $records,
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

        $estimate = $valuation->forMonth($business, Carbon::parse($record->period_start));

        return view('owner.settings.billing-statement', [
            'business' => $business,
            'record' => $record,
            'charged' => (int) ($counts['billable'] ?? 0),
            'freeUsed' => (int) ($counts['free_credit'] ?? 0),
            'overCap' => (int) ($counts['over_cap'] ?? 0),
            'textCampaignsCents' => $textCampaignsCents,
            'estimatedSalesCents' => $estimate['estimated_sales_cents'],
        ]);
    }

    /**
     * Show the setup checklist on the Overview again (spec
     * Section 5.8: dismissed state is resettable from Settings).
     */
    public function resetChecklist(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

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
    public function closeAccount(Request $request): RedirectResponse
    {
        $request->validate([
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Check the box to confirm you understand what closing does.',
        ]);

        $user = $request->user();
        $business = $user->business;

        if ($business->isClosed()) {
            return redirect()->route('settings.account');
        }

        DB::transaction(function () use ($business) {
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

        AuditLog::write('business.closed', $user, [], $business);

        return redirect()->route('dashboard')->with('status', 'Your account is closed.');
    }
}
