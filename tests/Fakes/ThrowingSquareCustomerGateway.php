<?php

namespace Tests\Fakes;

use App\Contracts\SquareCustomerGateway;
use App\Models\Business;
use RuntimeException;

/**
 * A Square gateway double that throws instead of returning,
 * simulating a catastrophic gateway failure mid-signup (matrix
 * AUTH-37, rewritten in the Phase 1 fix wave to the Spec 4.1
 * behavior). The spec's answer to a provisioning failure, throwing
 * included, is a completed signup with the customer ID null in the
 * pending provisioning state.
 */
class ThrowingSquareCustomerGateway implements SquareCustomerGateway
{
    public function createCustomerFor(Business $business): ?string
    {
        throw new RuntimeException('Simulated catastrophic Square gateway failure.');
    }
}
