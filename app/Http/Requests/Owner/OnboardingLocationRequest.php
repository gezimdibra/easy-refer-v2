<?php

namespace App\Http\Requests\Owner;

use App\Support\AddressRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The onboarding Location step's save (RS2-2): the signup
 * address rules (App\Support\AddressRules), applied by an
 * authenticated owner to their own business. The country is
 * normalized (upper-cased, and the US state with it) exactly as
 * RegisterBusinessRequest normalized it at signup, so a stored
 * address reads the same whichever door it came through.
 */
class OnboardingLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->business !== null;
    }

    protected function prepareForValidation(): void
    {
        $country = strtoupper(trim((string) $this->input('country', '')));

        $merge = ['country' => $country];

        if ($country === 'US') {
            $state = strtoupper(trim((string) $this->input('state', '')));

            // F-2 carried to the address's new home (RS2-2 merge,
            // 2026-10-30): a full state NAME posted directly
            // ("New York") normalizes to its code, exactly as the
            // register form's server-side rule did before the
            // address moved here — a typed name never dead-ends.
            if (! array_key_exists($state, (array) config('countries.us_states'))) {
                $byName = array_change_key_case(
                    array_flip((array) config('countries.us_states')),
                    CASE_UPPER
                );
                $state = $byName[$state] ?? $state;
            }

            $merge['state'] = $state;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return AddressRules::rules(fn (string $key) => $this->input($key));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(AddressRules::messages(), [
            'max' => 'That entry is too long. Shorten it and try again.',
        ]);
    }
}
