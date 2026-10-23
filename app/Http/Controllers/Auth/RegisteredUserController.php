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
    public function create(): View
    {
        return view('auth.register');
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
                'account' => 'An account with these details already exists. Sign in or reset your password instead.',
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

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.login_at', now()->timestamp);
        $request->session()->put('auth.rotated_at', now()->timestamp);

        AuditLog::write('owner.registered', $user);

        return redirect()->route('dashboard');
    }
}
