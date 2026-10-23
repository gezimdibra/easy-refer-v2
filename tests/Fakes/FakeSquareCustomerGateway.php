<?php

namespace Tests\Fakes;

use App\Contracts\SquareCustomerGateway;
use App\Models\Business;

/**
 * Test double for the Phase 4 Square gateway: behaves like a healthy
 * Square sandbox customer creation and records what it was asked for.
 */
class FakeSquareCustomerGateway implements SquareCustomerGateway
{
    /**
     * @var list<int>
     */
    public array $createdForBusinessIds = [];

    public function createCustomerFor(Business $business): ?string
    {
        $this->createdForBusinessIds[] = $business->id;

        return 'sq_fake_customer_'.$business->id;
    }
}
