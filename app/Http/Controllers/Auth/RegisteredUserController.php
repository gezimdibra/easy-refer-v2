<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\SquareCustomerGateway;
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
use Illuminate\Support\Facades\Log;
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

        return view('auth.register', [
            'prefillType' => $prefill['business_type'] ?? null,
        ]);
    }

    /**
     * Create the business, its owner, and the business's launch QR code
     * in one transaction (spec Section 4.1), grant the one-time free
     * visit credit (OD-1: 5 verified visits), then create the Square
     * customer through the gateway OUTSIDE the transaction so a slow or
     * failing network call never holds the signup open; a missing Square
     * customer never fails the signup (Phase 4 retries provisioning).
     * The database unique indexes on the canonical identity back the
     * form-level duplicate check: a racer who slips between the check
     * and the insert gets the same generic error, never a 500.
     */
    public function store(RegisterBusinessRequest $request, SquareCustomerGateway $square): RedirectResponse
    {
        $data = $request->validated();

        try {
            $user = DB::transaction(function () use ($data, $request) {
                $business = Business::create([
                    'name' => $data['business_name'],
                    'business_type' => $data['business_type'] ?? null,
                    'contact_email' => $data['email'],
                    'contact_phone_e164' => $request->input('phone_e164'),
                    'country' => $data['country'],
                    'street' => $data['street'],
                    'city' => $data['city'],
                    'state' => $data['state'],
                    'postal_code' => $data['postal_code'],
                    'free_visits_remaining' => (int) config('billing.free_visits_at_signup'),
                    // OD-1: every business starts on the launch plan;
                    // prices are read from the plans table everywhere.
                    'current_plan_id' => \App\Models\Plan::launch()?->id,
                ]);

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
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'account' => 'An account with these details already exists. Sign in or reset your password.',
            ]);
        }

        // Belt and braces on the contract: a gateway that throws
        // anyway gets the same end state as one that returns null.
        try {
            $squareCustomerId = $square->createCustomerFor($user->business);
        } catch (\Throwable) {
            Log::error('Square customer gateway threw during signup; provisioning deferred.', [
                'business_id' => $user->business->id,
            ]);

            $squareCustomerId = null;
        }

        if ($squareCustomerId !== null) {
            $user->business->forceFill(['square_customer_id' => $squareCustomerId])->save();
        }

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

        if ($ticketDollars > 0) {
            $user->business->forceFill(['average_ticket_cents' => $ticketDollars * 100])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.login_at', now()->timestamp);
        $request->session()->put('auth.rotated_at', now()->timestamp);

        AuditLog::write('owner.registered', $user);

        return redirect()->route('dashboard');
    }
}
