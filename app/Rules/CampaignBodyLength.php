<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The one campaign-body length rule (spec Section 10.3): the
 * store request and the estimate endpoint both validate with
 * it, so the compose counter can never price a body the store
 * would refuse (FB-03). The bound is an abuse ceiling of ten
 * segments' worth of UCS-2, measured in UTF-16 units (a
 * character above the BMP costs two); the segment counter, not
 * this cap, is the everyday discipline.
 */
class CampaignBodyLength implements ValidationRule
{
    public const MAX_UNITS = 670;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $units = intdiv(strlen(mb_convert_encoding((string) $value, 'UTF-16BE', 'UTF-8')), 2);

        if ($units > self::MAX_UNITS) {
            $fail('This message is too long. Shorten it and try again.');
        }
    }
}
