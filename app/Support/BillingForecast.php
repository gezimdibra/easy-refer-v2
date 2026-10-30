<?php

namespace App\Support;

use App\Models\Business;
use App\Models\PaymentMethod;
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
 *
 * Train 3.5 (retro 2026-10-25, audit Section 4 seam 1): the
 * schedule fires at 03:00 UTC on the 1st, which is the
 * owner's last evening of the prior month in every US
 * timezone. Owner-facing surfaces therefore name the charge
 * in business-local terms (nextChargeDateFor, through
 * Business::localTime()); nextChargeDate() itself stays the
 * UTC schedule date and remains the value the logic compares
 * against (the reminders cycle key, the expiry predicate).
 * The billing month stays UTC-anchored exactly as decided;
 * only the naming converts.
 */
final class BillingForecast
{
    /**
     * The next charge date on the schedule's own calendar:
     * the 1st of next month, UTC. A logic value (cycle keys,
     * date comparisons), never an owner-facing name on its
     * own: owners experience the charge on its local date,
     * which nextChargeDateFor() renders.
     */
    public static function nextChargeDate(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->startOfMonth()->addMonth()->startOfDay();
    }

    /**
     * The instant the next charge actually fires: the 1st of
     * next month at 03:00 in the app timezone (UTC), the
     * ChargeBilling schedule in routes/console.php
     * (monthlyOn(1, '03:00')).
     */
    public static function nextChargeInstant(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->startOfMonth()->addMonth()->setTime(3, 0);
    }

    /**
     * The next charge as the business's wall clock reads it:
     * the local date (and time) of the scheduled instant. For
     * a US business this is the evening of the last day of the
     * month the schedule calls the 1st.
     */
    public static function nextChargeDateFor(Business $business, ?Carbon $at = null): Carbon
    {
        return $business->localTime(self::nextChargeInstant($at));
    }

    /** The current period's last day. */
    public static function periodEnd(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->endOfMonth()->startOfDay();
    }

    /**
     * The current period's end as the business's wall clock
     * reads it: the local reading of the period's closing
     * instant (the UTC month's last moment). For a US business
     * the local date is still the month's last day, now named
     * from the owner's own clock rather than from UTC.
     */
    public static function periodEndFor(Business $business, ?Carbon $at = null): Carbon
    {
        return $business->localTime(($at ?? now())->copy()->endOfMonth());
    }

    /**
     * The one charge-expectation gate (Train A2, Amendment 37):
     * a charge is really expected on the scheduled date only
     * when the COMBINED forecast total (visits + texts) reaches
     * the Square minimum AND a default card exists to charge.
     * Below the minimum the charger carries the total forward
     * and charges nothing on that date; with no card it skips
     * the business entirely. Every surface that names a charge
     * date (the forecast line, the forecast email, the
     * card-expiry guard) reads this gate, so none of them can
     * promise a charge the run will not make.
     *
     * @param  array{total_cents: int}|null  $forecast
     */
    public static function chargeIsExpected(Business $business, ?array $forecast = null, ?bool $hasDefaultCard = null): bool
    {
        // Demo double exclusion (A-18 Finding 3): no charge is
        // ever expected for the demo business, so no forecast
        // surface (line, email, card-expiry guard) can name a
        // charge date for it. The flag leg is here; the
        // coverage leg is in the settlement walk the forecast
        // preview reads.
        if ($business->isDemo()) {
            return false;
        }

        $forecast ??= app(SettlementService::class)->forecastPreview($business);

        return (int) $forecast['total_cents'] >= (int) config('billing.minimum_charge_cents', 100)
            && ($hasDefaultCard ?? PaymentMethod::defaultFor($business) !== null);
    }

    /**
     * The forecast preview an owner surface may render: the
     * real preview when a charge is expected, zeroed when the
     * gate is closed, so the partial renders nothing in the
     * gated states (no card, sub-minimum, dormant) instead of
     * naming a charge date that will not happen.
     *
     * Callers that already hold this request's card fact (the
     * dashboard's setup checklist computes exactly
     * PaymentMethod::defaultFor() !== null; the billing page
     * already loaded the card) pass it in, and callers that
     * already hold the text figure pass it too, so the gate
     * adds no query to a measured page.
     *
     * @return array{billable_events: int, visit_cents: int, text_cents: int, total_cents: int}
     */
    public static function gatedPreview(Business $business, ?array $settlement = null, ?bool $hasDefaultCard = null, ?int $textCents = null): array
    {
        $forecast = app(SettlementService::class)->forecastPreview($business, $settlement, $textCents);

        if (! self::chargeIsExpected($business, $forecast, $hasDefaultCard)) {
            return ['billable_events' => 0, 'visit_cents' => 0, 'text_cents' => 0, 'total_cents' => 0];
        }

        return $forecast;
    }

    /**
     * The BILL-FC-01 line, or null when no charge is expected:
     * a gated forecast renders nowhere (silence = no charge
     * coming on the named date).
     */
    public static function line(Business $business): ?string
    {
        $preview = app(SettlementService::class)->forecastPreview($business);

        if (! self::chargeIsExpected($business, $preview)) {
            return null;
        }

        return self::lineFrom($preview, now(), $business);
    }

    /**
     * The line's dates name the business-local experience of
     * the charge when the business is known (Train 3.5). The
     * business-less form keeps the UTC schedule naming and
     * exists only for callers that hold no business; owner
     * surfaces always pass theirs.
     *
     * The preview contract is the forecast key set (Train A2):
     * billable_events / visit_cents / text_cents / total_cents.
     * The composition is named whenever texts are nonzero, and
     * a texts-only accrual gets its own branch: the charge is
     * real even when no visit has counted.
     *
     * @param  array{billable_events: int, visit_cents: int, text_cents: int, total_cents: int}  $preview
     */
    public static function lineFrom(array $preview, Carbon $at, ?Business $business = null): ?string
    {
        // Truth sweep 2026-10-30, item 2j rider: the legacy
        // visit-only arm (a preview without total_cents,
        // rendered from accrued_cents alone) is DELETED. It was
        // dead code on live paths — both partial callers pass
        // gatedPreview() output, which always carries
        // total_cents and is zeroed when the gate is closed —
        // and it was a latent trap: a legacy-shaped preview
        // rendered an expected-charge line WITHOUT passing
        // chargeIsExpected(). A preview that does not carry
        // the gated contract now renders nothing.
        if (! isset($preview['total_cents'])) {
            return null;
        }

        if ((int) $preview['total_cents'] <= 0) {
            return null;
        }

        $n = (int) $preview['billable_events'];
        $texts = (int) $preview['text_cents'];

        $through = $business !== null
            ? self::periodEndFor($business, $at)->format('F j')
            : self::periodEnd($at)->format('F j');
        $chargeOn = $business !== null
            ? self::nextChargeDateFor($business, $at)->format('F j')
            : self::nextChargeDate($at)->format('F j');

        if ($texts > 0 && $n === 0) {
            return 'Counted so far: '.self::amount($texts).' in text charges'
                .' through '.$through
                .'. Expected charge on '.$chargeOn
                .': '.self::amount((int) $preview['total_cents']).'.';
        }

        $line = 'Counted so far: '.$n.' '.($n === 1 ? 'visit' : 'visits')
            .' through '.$through
            .'. Expected charge on '.$chargeOn
            .': '.self::amount((int) $preview['total_cents']);

        if ($texts > 0) {
            $line .= ', including '.self::amount($texts).' in text charges';
        }

        return $line.'.';
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
    /**
     * B1 (R5 pack): the forecast email's fact-well rows, rendered
     * from the same preview the body composes, so the well can
     * never disagree with the paragraph. Null exactly when the
     * body is null.
     *
     * Train A2 (F-05): the well's charge date is the SAME
     * business-local date the body names (nextChargeDateFor),
     * never the UTC schedule date: one email, one date. The
     * text row renders only when texts are nonzero.
     *
     * @return array{visits: int, texts: string|null, chargeOn: string, amount: string}|null
     */
    public static function emailFacts(Business $business): ?array
    {
        $preview = app(SettlementService::class)->forecastPreview($business);

        if (! self::chargeIsExpected($business, $preview)) {
            return null;
        }

        return [
            'visits' => (int) $preview['billable_events'],
            'texts' => (int) $preview['text_cents'] > 0 ? self::amount((int) $preview['text_cents']) : null,
            'chargeOn' => self::nextChargeDateFor($business)->format('F j, Y'),
            'amount' => self::amount((int) $preview['total_cents']),
        ];
    }

    public static function emailBody(Business $business): ?string
    {
        $preview = app(SettlementService::class)->forecastPreview($business);

        if (! self::chargeIsExpected($business, $preview)) {
            return null;
        }

        $n = (int) $preview['billable_events'];
        $texts = (int) $preview['text_cents'];
        $at = now();
        $periodEnd = self::periodEndFor($business, $at)->format('F j');
        $head = 'Your next monthly charge runs on '.self::nextChargeDateFor($business, $at)->format('F j').'. ';

        if ($texts > 0 && $n === 0) {
            return $head
                .'Counted so far: '.self::amount($texts).' in text charges'
                .' through '.$periodEnd
                .', for an expected charge of '.self::amount((int) $preview['total_cents'])
                .'. Text charges from campaigns you send between now and '.$periodEnd
                .' are added before the charge runs. See your billing page: '.route('settings.billing');
        }

        $body = $head
            .'Counted so far: '.$n.' '.($n === 1 ? 'visit' : 'visits')
            .' through '.$periodEnd
            .', for an expected charge of '.self::amount((int) $preview['total_cents']);

        if ($texts > 0) {
            $body .= ', including '.self::amount($texts).' in text charges';
        }

        return $body
            .'. Visits that count between now and '.$periodEnd
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
