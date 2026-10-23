<?php

namespace App\Services\Square;

use App\Contracts\SquareCustomerGateway;
use App\Models\Business;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1 placeholder gateway. Records the intent and returns null so the
 * business row carries no Square customer until the real SDK gateway is
 * bound in Phase 4 (spec Section 9). Never throws.
 */
class NullSquareCustomerGateway implements SquareCustomerGateway
{
    public function createCustomerFor(Business $business): ?string
    {
        Log::info('Square customer creation deferred to the Phase 4 gateway.', [
            'business_id' => $business->id,
        ]);

        return null;
    }
}
