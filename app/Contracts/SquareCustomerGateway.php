<?php

namespace App\Contracts;

use App\Models\Business;

/**
 * Creates the Square Customer for a business at signup (spec Section 4.1).
 *
 * Phase 1 binds a null implementation so signup never depends on Square
 * availability. Phase 4 binds the SDK implementation (spec Section 9).
 * Implementations return the Square customer ID, or null when no customer
 * could be created; a null result must never fail the signup itself.
 */
interface SquareCustomerGateway
{
    public function createCustomerFor(Business $business): ?string;
}
