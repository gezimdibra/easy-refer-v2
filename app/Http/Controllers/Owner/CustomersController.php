<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Jobs\SendOwnerAddConfirmationSms;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\SmsOptOut;
use App\Support\OpaqueToken;
use App\Support\PhoneNumber;
use App\Support\Search;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Customers surface (spec Section 5.2): this business's
 * memberships only, searchable by name or phone digits. Adding a
 * customer creates or links the person and records the consent
 * provenance honestly: owner-added is owner_asserted, displayed
 * unconfirmed, and not campaign-eligible until the customer
 * confirms by replying YES to the one confirmation text. A phone
 * that belongs to another business's customer shows only that
 * the person already uses Easy Refer; no cross-tenant fact about
 * them is ever rendered (Section 7.3).
 */
class CustomersController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;
        $search = trim((string) $request->query('q', ''));

        $memberships = Membership::query()
            ->where('business_id', $business->id)
            ->whereNull('removed_at')
            ->with('customer')
            ->withCount('referralsMade')
            ->withCount('rewards')
            ->when($search !== '', function ($query) use ($search) {
                $digits = preg_replace('/\D+/', '', $search);

                $query->whereHas('customer', function ($customerQuery) use ($search, $digits) {
                    // Literal match (XT-46): the input is text,
                    // never a LIKE pattern.
                    $customerQuery->whereRaw(Search::likeClause('first_name'), [Search::contains($search)])
                        ->orWhereRaw(Search::likeClause('last_name'), [Search::contains($search)]);

                    if (strlen((string) $digits) >= 3) {
                        $customerQuery->orWhere('phone_e164', 'like', '%'.$digits);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('owner.customers.index', [
            'business' => $business,
            'memberships' => $memberships,
            'search' => $search,
        ]);
    }

    public function create(Request $request): View
    {
        return view('owner.customers.create', [
            'business' => $request->user()->business,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, \Closure $fail) {
                if (PhoneNumber::normalizeToE164((string) $value) === null) {
                    $fail('Enter a valid phone number.');
                }
            }],
            'consent' => ['nullable'],
        ], [
            'first_name.required' => 'Enter the customer name.',
            'max' => 'That entry is too long. Shorten it and try again.',
        ]);

        $business = $request->user()->business;
        $phone = PhoneNumber::normalizeToE164($data['phone']);
        $asserted = $request->boolean('consent');

        // A platform opt-out is the strongest negative signal in
        // the system: no consent may be recorded against it and
        // no text is sent (security verifier F-05). The customer
        // can still be added, without consent.
        $optedOut = SmsOptOut::optedOut($phone);

        try {
            [$membership, $state, $alreadyUses] = $this->resolveMembership($business, $phone, $data);
        } catch (QueryException) {
            // A concurrent add of the same phone won the insert
            // race; the winner's rows are the answer, not a 500
            // (security verifier F-10).
            [$membership, $state, $alreadyUses] = $this->resolveMembership($business, $phone, $data);
        }

        // The assertion applies to every path that lands here,
        // including re-adding someone who is already a member
        // without consent (security verifier F-06); it is the
        // only consent this surface records, and an opt-out
        // blocks it entirely.
        $assertionApplied = false;

        if ($asserted && ! $optedOut && ! $membership->sms_consent) {
            $membership->forceFill([
                'sms_consent' => true,
                'consent_source' => 'owner_asserted',
                'consent_updated_at' => now(),
            ])->save();

            ConsentEvent::create([
                'membership_id' => $membership->id,
                'customer_id' => $membership->customer_id,
                'business_id' => $business->id,
                'event_type' => 'provenance_asserted',
                'source' => 'owner_asserted',
                'consent_wording_version' => (string) config('referrals.consent_wording_version'),
                'flow' => 'owner_add',
                'ip_address' => $request->ip(),
            ]);

            SendOwnerAddConfirmationSms::dispatch($membership->id);
            $assertionApplied = true;
        }

        AuditLog::write('customer.added', $request->user(), [
            'outcome' => $alreadyUses ? 'linked' : $state,
            'consent_asserted' => $assertionApplied,
            'consent_blocked_opt_out' => $asserted && $optedOut,
        ], $business, $membership);

        if ($state === 'active') {
            return redirect()
                ->route('customers.show', $membership)
                ->with('status', 'This customer already uses Easy Refer. They are now connected to your store.');
        }

        $message = $alreadyUses
            ? 'This customer already uses Easy Refer. They are now connected to your store.'
            : ($assertionApplied ? 'Customer added. Their referral link is on its way by text.' : 'Customer added.');

        return redirect()->route('customers.index')->with('status', $message);
    }

    /**
     * Find or create the customer and this business's membership
     * for an owner add. Returns the membership, how it was
     * reached ('created', 'restored', or 'active'), and the one
     * cross-tenant boolean this surface may learn (the person
     * already uses Easy Refer at another business; Section 7.3).
     *
     * @param  array{first_name: string, last_name?: ?string}  $data
     * @return array{0: Membership, 1: string, 2: bool}
     */
    private function resolveMembership(Business $business, string $phone, array $data): array
    {
        $customer = Customer::query()->where('phone_e164', $phone)->first();
        $alreadyUses = false;

        if ($customer === null) {
            $customer = Customer::create([
                'phone_e164' => $phone,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? '',
            ]);
        } else {
            $alreadyUses = Membership::query()
                ->where('customer_id', $customer->id)
                ->where('business_id', '!=', $business->id)
                ->whereNull('removed_at')
                ->exists();
        }

        $membership = Membership::query()
            ->where('business_id', $business->id)
            ->where('customer_id', $customer->id)
            ->first();

        if ($membership !== null && $membership->removed_at === null) {
            return [$membership, 'active', $alreadyUses];
        }

        if ($membership !== null) {
            // Re-adding someone previously removed restores the
            // connection; the history was never deleted.
            $membership->forceFill([
                'removed_at' => null,
                'share_token_state' => 'active',
            ])->save();

            return [$membership, 'restored', $alreadyUses];
        }

        $membership = Membership::create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'share_token_hash' => OpaqueToken::hash($plainToken = OpaqueToken::generate()),
            'share_token_encrypted' => $plainToken,
            'share_token_state' => 'active',
            'sms_consent' => false,
            'consent_source' => 'owner_asserted',
            'consent_wording_version' => (string) config('referrals.consent_wording_version'),
            'consent_updated_at' => now(),
        ]);

        return [$membership, 'created', $alreadyUses];
    }

    public function show(Request $request, Membership $membership): View
    {
        $business = $request->user()->business;
        abort_unless($membership->business_id === $business->id && $membership->removed_at === null, 404);

        $membership->load('customer');

        $referralsMade = Referral::query()
            ->where('business_id', $business->id)
            ->where('referrer_membership_id', $membership->id)
            ->with(['referredCustomer', 'reward'])
            ->orderByDesc('created_at')
            ->get();

        $visits = Referral::query()
            ->where('business_id', $business->id)
            ->where('referred_customer_id', $membership->customer_id)
            ->with(['referrerMembership.customer'])
            ->orderByDesc('created_at')
            ->get();

        // The consent line on the detail carries its last-changed
        // date (design Section 3.4): the newest consent event for
        // this membership, if any exists.
        $consentChangedAt = ConsentEvent::query()
            ->where('membership_id', $membership->id)
            ->orderByDesc('created_at')
            ->value('created_at');

        return view('owner.customers.show', [
            'business' => $business,
            'membership' => $membership,
            'referralsMade' => $referralsMade,
            'rewards' => $membership->rewards()->with('referral.referredCustomer')->orderByDesc('created_at')->get(),
            'visits' => $visits,
            'consentChangedAt' => $consentChangedAt !== null ? \Illuminate\Support\Carbon::parse($consentChangedAt) : null,
        ]);
    }

    /**
     * Remove from this store (design Section 3.4): ends this
     * store's connection and revokes the share link; the person's
     * record and this store's history stay saved (the membership
     * row is marked, never deleted, because referrals cascade on
     * membership deletion).
     */
    public function destroy(Request $request, Membership $membership): RedirectResponse
    {
        $business = $request->user()->business;
        abort_unless($membership->business_id === $business->id, 404);

        $membership->forceFill([
            'removed_at' => now(),
            'share_token_state' => 'revoked',
        ])->save();

        AuditLog::write('customer.removed_from_store', $request->user(), [], $business, $membership);

        $name = trim($membership->customer->first_name.' '.$membership->customer->last_name);

        return redirect()
            ->route('customers.index')
            ->with('status', ($name === '' ? 'Customer' : $name).' was removed from your store.');
    }
}
