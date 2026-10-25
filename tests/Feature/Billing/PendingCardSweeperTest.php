<?php

namespace Tests\Feature\Billing;

use App\Contracts\SquareCustomerGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Services\Billing\CardResult;
use App\Services\Billing\PendingCardSweeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSquareCustomerGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Fakes\ThrowingSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The pending-card resolver (billing UI spec Sections 3 and 7
 * step 4, build unit BU-4): a card save whose Square outcome was
 * unknown leaves its row pending; the sweeper later asks the
 * gateway what Square holds for that save and settles the row to
 * a truthful state. Adopted cards become the default and
 * activate the business, exactly as a synchronous save would;
 * a save Square never completed fails truthfully; an answer
 * that is still unknown leaves the row pending for the next run.
 */
class PendingCardSweeperTest extends TestCase
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

    private function pendingCard(Business $business, int $minutesAgo = 30): PaymentMethod
    {
        $method = PaymentMethod::create([
            'business_id' => $business->id,
            'state' => PaymentMethod::STATE_PENDING,
            'is_default' => false,
        ]);
        $method->forceFill([
            'idempotency_key' => 'card_'.$business->id.'_'.$method->id,
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ])->save();

        return $method;
    }

    public function test_adopts_the_card_square_holds_and_activates_the_business(): void
    {
        $business = World::business(['billing_status' => 'none', 'square_customer_id' => 'sq_cust_1']);
        $method = $this->pendingCard($business);
        $this->gateway->resolvedCards[$method->idempotency_key] = CardResult::succeeded('sq_card_held', 'VISA', '1111', 1, 2031);

        $summary = app(PendingCardSweeper::class)->sweep();

        $this->assertSame(['adopted' => 1, 'failed' => 0, 'still_pending' => 0], $summary);
        $method->refresh();
        $this->assertSame(PaymentMethod::STATE_SUCCEEDED, $method->state);
        $this->assertSame('sq_card_held', $method->square_card_id);
        $this->assertSame('VISA', $method->card_brand);
        $this->assertSame('1111', $method->last4);
        $this->assertTrue($method->is_default);
        $this->assertSame('active', $business->fresh()->billing_status);
        $this->assertSame(1, AuditLog::query()->where('action', 'billing.card_added')->count());
    }

    public function test_adoption_demotes_the_existing_default(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $existing = PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_old',
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => 3,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_old',
        ]);
        $method = $this->pendingCard($business);
        $this->gateway->resolvedCards[$method->idempotency_key] = CardResult::succeeded('sq_card_held', 'VISA', '1111', 1, 2031);

        app(PendingCardSweeper::class)->sweep();

        $this->assertFalse($existing->fresh()->is_default);
        $this->assertTrue($method->fresh()->is_default);
    }

    public function test_a_definitive_failure_marks_the_row_failed_with_its_error(): void
    {
        $business = World::business(['billing_status' => 'none', 'square_customer_id' => 'sq_cust_1']);
        $method = $this->pendingCard($business);
        $this->gateway->resolvedCards[$method->idempotency_key] = CardResult::failed('GENERIC_DECLINE', 'payment');

        $summary = app(PendingCardSweeper::class)->sweep();

        $this->assertSame(['adopted' => 0, 'failed' => 1, 'still_pending' => 0], $summary);
        $method->refresh();
        $this->assertSame(PaymentMethod::STATE_FAILED, $method->state);
        $this->assertSame('GENERIC_DECLINE', $method->error_code);
        $this->assertSame('none', $business->fresh()->billing_status);
    }

    public function test_a_save_square_never_completed_fails_truthfully(): void
    {
        $business = World::business(['billing_status' => 'none', 'square_customer_id' => 'sq_cust_1']);
        $method = $this->pendingCard($business);
        // Nothing seeded for the key: Square holds no card for it.

        $summary = app(PendingCardSweeper::class)->sweep();

        $this->assertSame(['adopted' => 0, 'failed' => 1, 'still_pending' => 0], $summary);
        $method->refresh();
        $this->assertSame(PaymentMethod::STATE_FAILED, $method->state);
        $this->assertSame('card_not_created', $method->error_code);
    }

    public function test_an_unknown_resolution_leaves_the_row_pending(): void
    {
        $this->app->instance(SquarePaymentsGateway::class, new ThrowingSquarePaymentsGateway);
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $method = $this->pendingCard($business);

        $summary = app(PendingCardSweeper::class)->sweep();

        $this->assertSame(['adopted' => 0, 'failed' => 0, 'still_pending' => 1], $summary);
        $this->assertSame(PaymentMethod::STATE_PENDING, $method->fresh()->state);
    }

    public function test_young_pending_rows_are_not_swept(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $method = $this->pendingCard($business, minutesAgo: 5);
        $this->gateway->resolvedCards[$method->idempotency_key] = CardResult::succeeded('sq_card_held', 'VISA', '1111', 1, 2031);

        $summary = app(PendingCardSweeper::class)->sweep(olderThanMinutes: 15);

        $this->assertSame(['adopted' => 0, 'failed' => 0, 'still_pending' => 0], $summary);
        $this->assertSame(PaymentMethod::STATE_PENDING, $method->fresh()->state);
        $this->assertCount(0, $this->gateway->resolveCardCalls);
    }

    public function test_non_pending_rows_are_never_touched(): void
    {
        $business = World::business(['square_customer_id' => 'sq_cust_1']);
        $done = PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_done',
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => 3,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_done',
            'created_at' => now()->subDay(),
        ]);

        $summary = app(PendingCardSweeper::class)->sweep();

        $this->assertSame(['adopted' => 0, 'failed' => 0, 'still_pending' => 0], $summary);
        $this->assertSame(PaymentMethod::STATE_SUCCEEDED, $done->fresh()->state);
        $this->assertCount(0, $this->gateway->resolveCardCalls);
    }

    public function test_the_command_runs_the_sweep(): void
    {
        $business = World::business(['billing_status' => 'none', 'square_customer_id' => 'sq_cust_1']);
        $method = $this->pendingCard($business);
        $this->gateway->resolvedCards[$method->idempotency_key] = CardResult::succeeded('sq_card_held', 'VISA', '1111', 1, 2031);

        $this->artisan('billing:sweep-pending-cards')->assertSuccessful();

        $this->assertSame(PaymentMethod::STATE_SUCCEEDED, $method->fresh()->state);
    }
}
