<?php

namespace App\Console\Commands;

use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\BillingRefund;
use App\Services\Billing\RefundService;
use App\Services\Billing\SquareOutcomeUnknown;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * The operator refund path (money fix train, audit 3.2): the
 * working caller of RefundService::refund(). The refund policy
 * promises owners a process for proven fraudulent referrals
 * and billing errors within 30 days; before this command, no
 * code anywhere in the app called the refund service, so the
 * promise was support-manual end to end. Support runs a
 * refund from here, by record, optionally aimed at the one
 * visit the refund is about.
 *
 *   billing:refund {record} [--event=visit event id]
 *                            [--amount=cents] [--leg=visits|texts]
 *                            [--reason=text]
 */
class RefundBillingRecord extends Command
{
    protected $signature = 'billing:refund
        {record : The billing record id to refund}
        {--event= : The billing event id of the specific visit this refund is about}
        {--amount= : The refund amount in cents (defaults to the refundable remainder, or to the targeted visit amount)}
        {--leg=visits : Which leg the refund belongs to: visits or texts}
        {--reason= : The reason recorded on the refund row}';

    protected $description = 'Refund a billing record, optionally for one specific visit';

    public function handle(RefundService $refunds): int
    {
        $record = BillingRecord::query()->find((int) $this->argument('record'));

        if ($record === null) {
            $this->error('No billing record with that id.');

            return self::FAILURE;
        }

        $event = null;
        $eventId = $this->option('event');

        if ($eventId !== null) {
            $event = BillingEvent::query()->find((int) $eventId);

            if ($event === null) {
                $this->error('No billing event with that id.');

                return self::FAILURE;
            }
        }

        $amount = $this->option('amount');

        try {
            $refund = $refunds->refund(
                $record,
                $amount !== null ? (int) $amount : null,
                $this->option('reason'),
                'owner',
                $event,
                (string) $this->option('leg'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (SquareOutcomeUnknown) {
            $this->error('Square did not answer; the refund row stays pending for reconciliation.');

            return self::FAILURE;
        }

        if ($refund->state !== BillingRefund::STATE_SUCCEEDED) {
            $this->error('The refund did not go through; nothing was clawed back.');

            return self::FAILURE;
        }

        $this->info('Refunded '.$refund->amount_cents.' cents on record '.$record->id.' (refund '.$refund->id.').');

        return self::SUCCESS;
    }
}
