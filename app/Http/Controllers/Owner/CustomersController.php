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
use App\Support\ActionToast;
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

        // Demo double exclusion, ruled out here in code
        // (A-18 Finding 3): memberships carry no demo stamp,
        // so there is no second leg to apply on this list;
        // the flag leg is the business_id scoping itself (the
        // demo business has no owner account, so its
        // memberships can never be selected), and the detail
        // page's referral reads below apply the stamp leg
        // through DemoExclusion. Pinned in DemoPhase1FixTest.
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

        // CF-5 duplicate slot (Amendment 29 [CF-4]): the store
        // path already detects an active membership for this
        // business (resolveMembership's 'active' state), but it
        // used to answer with a redirect and a status line. The
        // designed answer is a field error at the phone, naming
        // the existing record, with a link to their page (the
        // link rides the session to the form's phone slot). The
        // one exception is the F-06 path: a re-add that asserts
        // consent for a member who does not have it yet performs
        // a real consent write below, so it is not a pure
        // duplicate and keeps its shipped behavior.
        $existingCustomer = Customer::query()->where('phone_e164', $phone)->first();
        if ($existingCustomer !== null) {
            $existingMembership = Membership::query()
                ->where('business_id', $business->id)
                ->where('customer_id', $existingCustomer->id)
                ->whereNull('removed_at')
                ->first();
            if ($existingMembership !== null) {
                $assertionWouldApply = $asserted && ! $optedOut && ! $existingMembership->sms_consent;
                if (! $assertionWouldApply) {
                    $existingName = trim($existingCustomer->first_name.' '.$existingCustomer->last_name);

                    return back()
                        ->withErrors(['phone' => 'This number is already on '.($existingName !== '' ? $existingName : 'a customer')."'s customer record."])
                        ->withInput()
                        ->with('duplicate_membership', $existingMembership->id);
                }
            }
        }

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
            : ($assertionApplied ? 'Customer added. A confirmation text is on its way; their referral link follows when they reply YES.' : 'Customer added.');

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

        // Demo double exclusion (A-18 Finding 3): owner
        // scoping is the flag leg; the helper adds the stamp leg.
        $referralsMade = \App\Support\DemoExclusion::referrals(Referral::query())
            ->where('business_id', $business->id)
            ->where('referrer_membership_id', $membership->id)
            ->with(['referredCustomer', 'reward'])
            ->orderByDesc('created_at')
            ->get();

        $visits = \App\Support\DemoExclusion::referrals(Referral::query())
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
            'rewards' => $membership->rewards()->with(['referral.referredCustomer', 'business'])->orderByDesc('created_at')->get(),
            'visits' => $visits,
            'consentChangedAt' => $consentChangedAt !== null ? \Illuminate\Support\Carbon::parse($consentChangedAt) : null,
        ]);
    }

    /**
     * Edit a customer's name (A-080, small-gaps design Section
     * 1.3): the name only. The phone is the person's identity key,
     * not an attribute (design 1.4): it renders read-only here and
     * update() never reads a phone field, so no submitted value
     * can re-key the person, move their consent provenance, or
     * re-point their sign-in codes.
     */
    public function edit(Request $request, Membership $membership): View
    {
        $business = $request->user()->business;
        abort_unless($membership->business_id === $business->id && $membership->removed_at === null, 404);

        $membership->load('customer');

        return view('owner.customers.edit', [
            'business' => $business,
            'membership' => $membership,
        ]);
    }

    public function update(Request $request, Membership $membership): RedirectResponse
    {
        $business = $request->user()->business;
        abort_unless($membership->business_id === $business->id && $membership->removed_at === null, 404);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
        ], [
            'first_name.required' => 'Enter the customer name.',
            'max' => 'That entry is too long. Shorten it and try again.',
        ]);

        $customer = $membership->customer;
        $first = $data['first_name'];
        $last = $data['last_name'] ?? '';

        // The write is to the person's one global name (design
        // 1.5), and only on a real change: a no-op save redirects
        // with the same status and writes no audit row, so the
        // trail records changes, not clicks. Consent state, the
        // share token, and the ConsentEvent log are never touched:
        // a name fix is not a consent event.
        $changed = [];
        $metadata = [];

        if ($first !== $customer->first_name) {
            $changed[] = 'first_name';
            $metadata['first_name'] = [$customer->first_name, $first];
        }

        if ($last !== $customer->last_name) {
            $changed[] = 'last_name';
            $metadata['last_name'] = [$customer->last_name, $last];
        }

        if ($changed !== []) {
            $customer->forceFill([
                'first_name' => $first,
                'last_name' => $last,
            ])->save();

            AuditLog::write('customer.details_updated', $request->user(),
                ['changed' => $changed] + $metadata, $business, $membership);
        }

        return redirect()
            ->route('customers.show', $membership)
            ->with('status', 'Customer details saved.');
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

        // RS-14: removal is reversible, so the toast carries
        // an Undo. The snapshot holds the RAW prior values so
        // the undo restores them byte-for-byte.
        $prior = [
            'membership_id' => $membership->id,
            'removed_at' => $membership->getRawOriginal('removed_at'),
            'share_token_state' => $membership->getRawOriginal('share_token_state'),
        ];

        $membership->forceFill([
            'removed_at' => now(),
            'share_token_state' => 'revoked',
        ])->save();

        AuditLog::write('customer.removed_from_store', $request->user(), [], $business, $membership);

        $name = trim($membership->customer->first_name.' '.$membership->customer->last_name);
        $displayName = $name === '' ? 'Customer' : $name;

        ActionToast::undoable(
            $request,
            $displayName.' was removed from your store.',
            'customer_removed',
            $prior,
            $displayName.' is back in your store.',
            'customers.index',
        );

        return redirect()
            ->route('customers.index')
            ->with('status', $displayName.' was removed from your store.');
    }
}
