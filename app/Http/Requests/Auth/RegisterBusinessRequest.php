<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\CanonicalEmail;
use App\Support\PasswordPolicy;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Signup validation for the four-step wizard (spec Section 4.1). The
 * wizard pages client-side; the server receives one submission and
 * validates every field here.
 */
class RegisterBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $country = strtoupper(trim((string) $this->input('country', '')));

        $merge = [
            'canonical_email' => CanonicalEmail::of((string) $this->input('email', '')),
            'phone_e164' => PhoneNumber::normalizeToE164($this->input('phone')),
            'country' => $country,
        ];

        if ($country === 'US') {
            $merge['state'] = strtoupper(trim((string) $this->input('state', '')));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail) {
                if (PhoneNumber::normalizeToE164((string) $value) === null) {
                    $fail('Enter a valid phone number, including the area code.');
                }
            }],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
            // Address validation against application data (spec Section
            // 4.1): the country must be a known ISO code; a US address
            // must name a real state, carry a well-formed ZIP, and put
            // the ZIP in that state's region.
            'country' => ['required', 'string', Rule::in(config('countries.codes'))],
            'street' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) {
                if ($this->input('country') === 'US'
                    && ! array_key_exists(strtoupper((string) $value), (array) config('countries.us_states'))) {
                    $fail('Select the state from the list.');
                }
            }],
            'postal_code' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                if ($this->input('country') !== 'US') {
                    return;
                }

                $zip = (string) $value;

                if (preg_match('/^\d{5}(-\d{4})?$/', $zip) !== 1) {
                    $fail('Enter a valid ZIP code.');

                    return;
                }

                $region = config('countries.zip_regions.'.substr($zip, 0, 1));

                if (! in_array(strtoupper((string) $this->input('state')), (array) $region, true)) {
                    $fail('That ZIP code is not in the selected state.');
                }
            }],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
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
                    'An account with these details already exists. Sign in or reset your password instead.'
                );
            }
        });
    }
}
