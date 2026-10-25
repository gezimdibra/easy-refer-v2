<?php

namespace App\Support;

use App\Models\Business;
use App\Services\Billing\SettlementService;
use Illuminate\Support\Carbon;

/**
 * The pre-charge forecast (RK-03, Train 2; copy Amendment 22
 * BILL-FC-01..03): what the next monthly charge will be, from
 * the one settlement preview, and when it runs. The charge
 * date is the billing schedule's own date (ChargeBilling,
 * monthly on the 1st at 03:00); the period end is the current
 * month's last day. Every surface — the billing page, the
 * dashboard Value panel, the forecast email — renders these
 * words from here, so the figures can never disagree.
 */
final class BillingForecast
{
    /** The next charge date: the 1st of next month. */
    public static function nextChargeDate(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->startOfMonth()->addMonth()->startOfDay();
    }

    /** The current period's last day. */
    public static function periodEnd(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->endOfMonth()->startOfDay();
    }

    /**
     * The BILL-FC-01 line, or null when nothing has accrued:
     * a zero forecast renders nowhere (silence = nothing due).
     */
    public static function line(Business $business): ?string
    {
        $preview = app(SettlementService::class)->accruedPreview($business);

        return self::lineFrom($preview, now());
    }

    /**
     * @param  array{accrued_cents: int, billable_events: int}  $preview
     */
    public static function lineFrom(array $preview, Carbon $at): ?string
    {
        if ($preview['accrued_cents'] <= 0) {
            return null;
        }

        $n = $preview['billable_events'];

        return 'Counted so far: '.$n.' '.($n === 1 ? 'visit' : 'visits')
            .' through '.self::periodEnd($at)->format('F j')
            .'. Expected charge on '.self::nextChargeDate($at)->format('F j')
            .': '.self::amount($preview['accrued_cents']).'.';
    }

    /**
     * Forecast amounts render with cents always shown, the
     * billing page's own format for its accrued line ($6.00,
     * never $6), so the surfaces agree digit for digit.
     */
    public static function amount(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    /**
     * The BILL-FC-02 forecast email body for a business, from
     * the same preview the line renders from; null when the
     * expected charge is zero (the email never sends then).
     */
    public static function emailBody(Business $business): ?string
    {
        $preview = app(SettlementService::class)->accruedPreview($business);

        if ($preview['accrued_cents'] <= 0) {
            return null;
        }

        $n = $preview['billable_events'];
        $at = now();

        return 'Your next monthly charge runs on '.self::nextChargeDate($at)->format('F j')
            .'. Counted so far: '.$n.' '.($n === 1 ? 'visit' : 'visits')
            .' through '.self::periodEnd($at)->format('F j')
            .', for an expected charge of '.self::amount($preview['accrued_cents'])
            .'. Visits that count between now and '.self::periodEnd($at)->format('F j')
            .' are added before the charge runs. See your billing page: '.route('settings.billing');
    }

    /**
     * Whether the daily reminders run is inside the forecast
     * window: the 3 days before the charge. Measured in whole
     * days to the charge date, so Oct 28 (3 whole days before
     * the Nov 1 charge) is inside and Oct 20 is not.
     */
    public static function inForecastWindow(?Carbon $at = null): bool
    {
        $at ??= now();
        $charge = self::nextChargeDate($at);

        return $at->lt($charge) && (int) $at->diffInDays($charge) <= 3;
    }
}
