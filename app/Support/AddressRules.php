<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rule;

/**
 * The one address rule set (RS2-2): the country must be a known
 * ISO code; a US address must name a real state, carry a
 * well-formed ZIP, and put the ZIP in that state's region
 * (spec Section 4.1, finding M-8). These rules lived in
 * RegisterBusinessRequest while the address was a signup step;
 * when the address moved to the onboarding Location step, the
 * rules were extracted here so the Location form validates
 * exactly as signup did. The Settings profile form carries the
 * plainer required-only twin by design (an existing business
 * editing its profile is not a signup).
 */
final class AddressRules
{
    /**
     * @param  Closure(string): mixed  $input  reads a sibling field (country / state) from the request being validated
     * @return array<string, mixed>
     */
    public static function rules(Closure $input): array
    {
        return [
            'country' => ['required', 'string', Rule::in(config('countries.codes'))],
            'street' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) use ($input) {
                if ($input('country') === 'US'
                    && ! array_key_exists(strtoupper((string) $value), (array) config('countries.us_states'))) {
                    $fail('Select the state from the list.');
                }
            }],
            'postal_code' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) use ($input) {
                if ($input('country') !== 'US') {
                    return;
                }

                $zip = (string) $value;

                if (preg_match('/^\d{5}(-\d{4})?$/', $zip) !== 1) {
                    $fail('Enter a valid ZIP code.');

                    return;
                }

                $region = config('countries.zip_regions.'.substr($zip, 0, 1));

                if (! in_array(strtoupper((string) $input('state')), (array) $region, true)) {
                    $fail('That ZIP code is not in the selected state.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'country.required' => 'Select your country.',
            'country.in' => 'Select your country from the list.',
            'street.required' => 'Enter your street address.',
            'city.required' => 'Enter your city.',
            'state.required' => 'Enter your state or province.',
            'postal_code.required' => 'Enter your postal code.',
        ];
    }
}
