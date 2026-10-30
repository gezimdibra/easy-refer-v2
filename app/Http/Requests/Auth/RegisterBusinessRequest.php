<?php

namespace App\Http\Requests\Auth;

use App\Models\Business;
use App\Models\User;
use App\Support\CanonicalEmail;
use App\Support\PasswordPolicy;
use App\Support\PhoneNumber;
use App\Support\UsState;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Signup validation for the wizard's identity checkpoint
 * (spec Section 4.1; RS2-2 Variant A). The wizard pages
 * client-side; the server receives one submission at Step 3
 * (Password) and validates the identity fields here, and the
 * account is created from this submission. The address is NOT
 * part of signup any more: it is collected by the onboarding
 * Location step under App\Support\AddressRules, the rule set
 * extracted from this request.
 */
class RegisterBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'canonical_email' => CanonicalEmail::of((string) $this->input('email', '')),
            'phone_e164' => PhoneNumber::normalizeToE164($this->input('phone')),
        ];

        $this->merge($merge);

        $this->enforceIdentifierBudget($merge['canonical_email'], $merge['phone_e164']);
    }

    /**
     * The per-identifier half of the signup budget (spec Section 8.3,
     * finding L-4). The route throttle keys by IP; this keys by the
     * canonical email and the E.164 phone, so attempts against one
     * identity are bounded no matter how the source address rotates.
     * Every submission counts, including ones other rules will reject:
     * the budget guards the identity, not the successful signups.
     */
    private function enforceIdentifierBudget(string $canonicalEmail, ?string $phoneE164): void
    {
        $max = (int) config('security.signup_throttle.per_identifier_max_attempts');
        $window = (int) config('security.signup_throttle.per_identifier_window_seconds');

        $keys = ['signup:email:'.hash('sha256', $canonicalEmail)];

        if ($phoneE164 !== null) {
            $keys[] = 'signup:phone:'.hash('sha256', $phoneE164);
        }

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'account' => 'Too many account creation attempts for these details. Please try again later.',
                ]);
            }
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, $window);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', Rule::in(Business::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail) {
                if (PhoneNumber::normalizeToE164((string) $value) === null) {
                    $fail('Enter a valid phone number, including the area code.');
                }
            }],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ];
    }

    /**
     * Plain-language messages for every rule (design Section 4.4,
     * F-03): a failed signup submit states each problem once, at
     * its field, in the product's words, never a framework
     * default. The password rule object's own messages are
     * overridden in lang/en/validation.php.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_name.required' => 'Enter your business name.',
            'business_type.in' => 'Select a business type from the list.',
            'name.required' => 'Enter your name.',
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address.',
            'phone.required' => 'Enter your phone number.',
            'password.required' => 'Create a password.',
            'password.confirmed' => 'The passwords do not match. Enter the same password twice.',
            'password.min' => 'Use at least 12 characters.',
            'password.max' => 'That password is too long. Shorten it and try again.',
            'max' => 'That entry is too long. Shorten it and try again.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // SMS-11: the signup submit is Turnstile-protected when
            // keys are configured (spec Section 10.4), verified
            // server-side before anything else is decided.
            if (! app(\App\Services\Security\TurnstileVerifier::class)
                ->passes($this->input('cf-turnstile-response'), $this->ip())) {
                $validator->errors()->add('account', 'We could not confirm this submission. Please try again.');

                return;
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $exists = User::query()
                ->where('canonical_email', $this->input('canonical_email'))
                ->orWhere('phone_e164', $this->input('phone_e164'))
                ->exists();

            if ($exists) {
                // Generic on purpose (spec Section 4.1): the response never
                // reveals whether the email or the phone matched.
                $validator->errors()->add(
                    'account',
                    'An account with these details already exists. Sign in or reset your password.'
                );
            }
        });
    }
}
