<?php

namespace App\Services\Billing;

use App\Mail\BillingForecastMail;
use App\Mail\CardExpiryGuardMail;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Services\Owner\ActionCenter;
use App\Support\BillingForecast;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * The daily forecast-reminders run (RK-03): two owner emails,
 * each sent at most once per charge cycle, with the audit log
 * as the once-per-cycle record (the dunning stages' pattern).
 *
 * - BILL-FC-02 forecast: inside the 3 days before the charge,
 *   only when the settlement preview is nonzero.
 * - BILL-FC-03 expiry guard: inside the 30 days before the
 *   card's expiry, only when the card on file expires before
 *   the next charge date (the Action Center's AC-08
 *   predicate, shared so email and inbox agree).
 *
 * The cycle key is the upcoming charge date, so a new month
 * opens a new cycle for both emails.
 */
class ForecastRemindersService
{
    /**
     * @return array{forecast: int, expiry_guard: int}
     */
    public function run(): array
    {
        $summary = ['forecast' => 0, 'expiry_guard' => 0];
        $cycle = BillingForecast::nextChargeDate()->toDateString();

        Business::query()
            ->whereNull('closed_at')
            ->orderBy('id')
            ->each(function (Business $business) use (&$summary, $cycle): void {
                $owner = $business->owner;

                if ($owner === null) {
                    return;
                }

                if (BillingForecast::inForecastWindow()
                    && ! $this->sentAlready($business, 'billing.forecast_email', $cycle)) {
                    $body = BillingForecast::emailBody($business);

                    if ($body !== null) {
                        Mail::to($owner->email)->send(new BillingForecastMail($business, $body));
                        AuditLog::write('billing.forecast_email', null, ['cycle' => $cycle], $business);
                        $summary['forecast']++;
                    }
                }

                $card = PaymentMethod::defaultFor($business);

                if ($card !== null
                    && ActionCenter::cardExpiresBeforeNextCharge($card)
                    && $this->insideExpiryWindow($card)
                    && ! $this->sentAlready($business, 'billing.expiry_guard_email', $cycle)) {
                    Mail::to($owner->email)->send(new CardExpiryGuardMail($business, $card));
                    AuditLog::write('billing.expiry_guard_email', null, ['cycle' => $cycle], $business);
                    $summary['expiry_guard']++;
                }
            });

        return $summary;
    }

    /** Inside the 30 days before the card's expiry month ends. */
    private function insideExpiryWindow(PaymentMethod $card): bool
    {
        $expiryMonthEnd = Carbon::create($card->exp_year, $card->exp_month, 1)->endOfMonth();

        return now()->gte($expiryMonthEnd->copy()->subDays(30)->startOfDay())
            && now()->lte($expiryMonthEnd);
    }

    private function sentAlready(Business $business, string $action, string $cycle): bool
    {
        return AuditLog::query()
            ->where('business_id', $business->id)
            ->where('action', $action)
            ->where('metadata->cycle', $cycle)
            ->exists();
    }
}
