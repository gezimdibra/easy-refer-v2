<?php

namespace App\Contracts;

use App\Models\Business;

/**
 * Creates the Square Customer for a business (spec Section 4.1).
 * Since RS2-2 the signup checkpoint creates none: provisioning
 * happens at the card moment (CardOnFileService) or at the
 * onboarding Location save, whichever comes first.
 *
 * The binding is the configured access token: with one, the SDK
 * gateway (App\Services\Square\SdkSquareCustomerGateway) provisions
 * for real; without one, the null gateway records the intent. Either
 * way, implementations return the Square customer ID, or null when no
 * customer could be created; a null result must never fail the signup
 * itself, and the Section 9.7 sweeper provisions the business later.
 */
interface SquareCustomerGateway
{
    public function createCustomerFor(Business $business): ?string;
}
