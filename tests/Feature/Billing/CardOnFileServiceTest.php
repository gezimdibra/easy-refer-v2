<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquareCustomerGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Services\Billing\CardOnFileException;
use App\Services\Billing\CardOnFileService;
use App\Services\Square\NullSquareCustomerGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSquareCustomerGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Card on file (spec Sections 9.6, 9.9; blueprint Section 1.1;
 * BILL-02, BILL-41): the row stores brand and last4 from the
 * gateway response only, exactly one card is default, and saving
 * the first working card is the activation act that flips
 * billing_status to active. Nothing is charged at activation.
 */
class CardOnFileServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        $this->app->instance(SquareCustomerGateway::class, new FakeSquareCustomerGateway);
    }

    public function test_save_stores_brand_and_last4_and_activates_the_business(): void
    {
        $business = World::business(['billing_status' => 'none', 'square_customer_id' => 'sq_cust_1']);

        $method = app(CardOnFileService::class)->saveCard($business, 'single-use-token');

        $this->assertSame(PaymentMethod::STATE_SUCCEEDED, $method->state);
        $this->assertSame('VISA', $method->card_brand);
        $this->assertSame('4242', $method->last4);
        $this->assertSame(12, $method->exp_month);
        $this->assertSame(2030, $method->exp_year);
        $this->assertTrue($method->is_default);
        $this->assertSame('card_'.$business->id.'_'.$method->id, $method->idempotency_key);
        $this->assertSame('active', $business->fresh()->billing_status);
        $this->assertTrue($business->fresh()->flowIsActive());
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.card_added')->count());
        // Activation charges nothing.
        $this->assertCount(0, $this->gateway->chargeCalls);
    }

    public function test_second_card_replaces_the_default_and_exactly_one_default_remains(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $service = app(CardOnFileService::class);

        $first = $service->saveCard($business, 'token-one');
        $second = $service->saveCard($business->fresh(), 'token-two');

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame(1, PaymentMethod::query()->where('is_default', true)->count());
        $this->assertSame($second->id, PaymentMethod::defaultFor($business)->id);
    }

    public function test_declined_card_save_fails_the_row_and_changes_no_default(): void
    {
        $business = World::business(['billing_status' => 'none', 'square_customer_id' => 'sq_cust_1']);
        $this->gateway->cardFailureCode = 'GENERIC_DECLINE';

        $method = app(CardOnFileService::class)->saveCard($business, 'bad-token');

        $this->assertSame(PaymentMethod::STATE_FAILED, $method->state);
        $this->assertSame('GENERIC_DECLINE', $method->error_code);
        $this->assertFalse($method->is_default);
        $this->assertNull(PaymentMethod::defaultFor($business));
        $this->assertSame('none', $business->fresh()->billing_status);
    }

    public function test_missing_square_customer_is_provisioned_before_the_save(): void
    {
        $business = World::business(['square_customer_id' => null]);

        $method = app(CardOnFileService::class)->saveCard($business, 'token');

        $this->assertSame(PaymentMethod::STATE_SUCCEEDED, $method->state);
        $this->assertSame('sq_fake_customer_'.$business->id, $business->fresh()->square_customer_id);
    }

    public function test_unprovisionable_customer_blocks_the_save_loudly(): void
    {
        $this->app->instance(SquareCustomerGateway::class, new NullSquareCustomerGateway);
        $business = World::business(['square_customer_id' => null]);

        $this->expectException(CardOnFileException::class);
        try {
            app(CardOnFileService::class)->saveCard($business, 'token');
        } finally {
            $this->assertSame(0, PaymentMethod::count());
            $this->assertCount(0, $this->gateway->saveCardCalls);
        }
    }

    public function test_removal_promotes_the_next_card_and_audits(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $service = app(CardOnFileService::class);

        $first = $service->saveCard($business, 'token-one');
        $second = $service->saveCard($business->fresh(), 'token-two');

        $service->removeCard($business->fresh(), $second);

        $this->assertSame(PaymentMethod::STATE_REMOVED, $second->fresh()->state);
        $this->assertSame($first->id, PaymentMethod::defaultFor($business)->id);
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.card_removed')->count());

        $service->removeCard($business->fresh(), $first->fresh());
        $this->assertNull(PaymentMethod::defaultFor($business));
    }
}
