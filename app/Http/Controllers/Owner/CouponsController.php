<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CouponBatch;
use App\Models\CouponClaim;
use App\Models\CouponHintDismissal;
use App\Models\ReferralEvent;
use App\Services\Coupons\CouponService;
use App\Support\QrImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The owner's Coupons tab (ID-11, S3; design pass Sections 1.1,
 * 6, 7): a secondary tab on the QR page, never a top-level nav
 * item and never on the dashboard. Batch-primary construction:
 * the owner creates named batches, prints one flyer sheet per
 * batch, and reads scans, claims, and redemptions per batch
 * from the same derived counts the counter produces.
 *
 * F-1 discipline: every action takes its batch and claim by
 * implicit model binding, never an int id, and every bound
 * model is scoped to the signed-in owner's business (a foreign
 * batch answers 404, the tenant-isolation rule).
 */
class CouponsController extends Controller
{
    public function __construct(private CouponService $coupons) {}

    public function index(Request $request): View
    {
        $business = $request->user()->business;

        if ($request->query('from') === 'hint') {
            ReferralEvent::record('coupon_hint_tapped', 'owner', $business);
        }

        $batches = $business->couponBatches()
            ->latest('id')
            ->get()
            ->map(function (CouponBatch $batch) {
                $batch->applyExpiry();
                $funnel = $this->coupons->batchFunnel($batch);

                return [
                    'model' => $batch,
                    'scans' => $funnel['scans'],
                    'claimed' => $funnel['claimed'],
                    'redeemed' => $funnel['redeemed'],
                    'statusWord' => $this->statusWord($batch),
                    'daysLeft' => $batch->status === CouponBatch::STATUS_ACTIVE
                        ? max(0, (int) now()->diffInDays($batch->expires_at, false))
                        : null,
                    'zeroLine' => $this->zeroLine($batch, $funnel),
                    'flagged' => $batch->isFlagged(),
                    'flagMintsLastHour' => $batch->claims()->where('created_at', '>=', now()->subHour())->count(),
                ];
            })
            ->all();

        $prefill = $this->coupons->prefillOffer($business);

        return view('owner.coupons', [
            'business' => $business,
            'batches' => $batches,
            'hasBatches' => $batches !== [],
            'prefill' => $prefill,
            'labelChips' => (array) config('coupons.label_chips'),
            'capOptions' => (array) config('coupons.claim_cap_options'),
            'expiryOptions' => (array) config('coupons.expiry_day_options'),
            'banner' => $this->pausedBanner($business),
            'ideas' => $this->ideasFor($business),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        $data = $request->validate([
            'offer_text' => ['required', 'string', 'max:200'],
            'label_chip' => ['nullable', 'string', 'max:60'],
            'label_custom' => ['nullable', 'string', 'max:40'],
            'claim_cap' => ['required', 'integer'],
            'expiry_days' => ['required', 'integer'],
            'prefill_source' => ['nullable', 'string', 'in:owner,pack,default'],
            'created_from' => ['nullable', 'string', 'in:hint,tab'],
        ], [
            'offer_text.required' => 'Your offer cannot be empty.',
        ]);

        $label = trim((string) ($data['label_custom'] ?? '')) !== ''
            ? trim((string) $data['label_custom'])
            : trim((string) ($data['label_chip'] ?? ''));

        if ($label === '') {
            return back()->withErrors(['label_chip' => 'Pick where these are going, or name it yourself.'])->withInput();
        }

        if (! in_array((int) $data['claim_cap'], (array) config('coupons.claim_cap_options'), true)) {
            return back()->withErrors(['claim_cap' => 'Pick one of the claim limits.'])->withInput();
        }

        if (! in_array((int) $data['expiry_days'], (array) config('coupons.expiry_day_options'), true)) {
            return back()->withErrors(['expiry_days' => 'Pick one of the lengths.'])->withInput();
        }

        // Creation leg (pass Section 2.4): a batch may be
        // ASSEMBLED behind a paused door (the form simply
        // re-renders with the owner's work intact via old
        // input), but it goes LIVE only when couponFlowActive
        // holds, so no one's first scan lands on a closed page.
        if (! $business->couponFlowActive()) {
            return back()
                ->with('coupon_activation_notice', 'Your program is not active right now, so this batch cannot go live yet. Your form is saved here. Fix it in Billing, then tap Create a batch again.')
                ->withInput();
        }

        $batch = $this->coupons->createBatch(
            $business,
            $request->user(),
            $label,
            trim((string) $data['offer_text']),
            (int) $data['claim_cap'],
            (int) $data['expiry_days'],
            (string) ($data['created_from'] ?? 'tab'),
            (string) ($data['prefill_source'] ?? 'owner'),
        );

        AuditLog::write('coupon.batch_created', $request->user(), ['batch_id' => $batch->id], $business);

        return redirect()->route('owner.qr.coupons.done', ['batch' => $batch]);
    }

    public function done(Request $request, CouponBatch $batch): View
    {
        $this->scope($request, $batch);
        $batch->applyExpiry();

        return view('owner.coupon-done', [
            'business' => $request->user()->business,
            'batch' => $batch,
        ]);
    }

    public function kill(Request $request, CouponBatch $batch): RedirectResponse
    {
        $this->scope($request, $batch);

        $this->coupons->killBatch($batch);

        AuditLog::write('coupon.batch_killed', $request->user(), ['batch_id' => $batch->id], $request->user()->business);

        return redirect()->route('owner.qr.coupons')->with('status', 'Batch stopped. Coupons already claimed stay good until their own dates run out.');
    }

    public function dismissFlag(Request $request, CouponBatch $batch): RedirectResponse
    {
        $this->scope($request, $batch);

        $batch->forceFill(['flag_cleared_at' => now()])->save();

        ReferralEvent::record('coupon_flag_dismissed', 'owner', $request->user()->business, null, null, null, [
            'batch_id' => $batch->id,
            'verdict' => 'looks_fine',
        ]);

        return redirect()->route('owner.qr.coupons')->with('status', 'Marked as fine.');
    }

    public function flyer(Request $request, CouponBatch $batch): View
    {
        $this->scope($request, $batch);
        $business = $request->user()->business;

        return view('owner.coupon-flyer', [
            'business' => $business,
            'batch' => $batch,
            'imageUrl' => route('owner.qr.coupons.flyer.image', ['batch' => $batch]),
            'shortLink' => preg_replace('#^https?://#', '', route('coupon.landing', ['token' => $batch->plainToken()])),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    public function flyerImage(Request $request, CouponBatch $batch): Response
    {
        $this->scope($request, $batch);

        return response(QrImage::svg(route('coupon.landing', ['token' => $batch->plainToken()])), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    public function claims(Request $request, CouponBatch $batch): View
    {
        $this->scope($request, $batch);
        $batch->applyExpiry();

        $claims = $batch->claims()
            ->with('customer')
            ->latest('id')
            ->get()
            ->each(fn (CouponClaim $claim) => $claim->applyExpiry());

        return view('owner.coupon-claims', [
            'business' => $request->user()->business,
            'batch' => $batch,
            'claims' => $claims,
        ]);
    }

    public function voidClaim(Request $request, CouponBatch $batch, CouponClaim $claim): RedirectResponse
    {
        $this->scope($request, $batch);
        abort_unless($claim->batch_id === $batch->id, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'in:'.implode(',', CouponClaim::VOID_REASONS)],
        ]);

        $this->coupons->voidClaim($claim, (string) $data['reason']);

        AuditLog::write('coupon.claim_voided', $request->user(), [
            'batch_id' => $batch->id,
            'coupon_claim_id' => $claim->id,
            'reason' => $data['reason'],
        ], $request->user()->business);

        return redirect()->route('owner.qr.coupons.claims', ['batch' => $batch])->with('status', 'Coupon cancelled.');
    }

    public function dismissHint(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        CouponHintDismissal::firstOrCreate(
            ['business_id' => $business->id, 'user_id' => $request->user()->id],
            ['dismissed_at' => now()],
        );

        ReferralEvent::record('coupon_hint_dismissed', 'owner', $business);

        return redirect()->route('owner.qr');
    }

    /** Tenant scope for a bound batch: a foreign batch is a 404. */
    private function scope(Request $request, CouponBatch $batch): void
    {
        abort_unless($batch->business_id === $request->user()->business_id, 404);
    }

    private function statusWord(CouponBatch $batch): string
    {
        return match ($batch->status) {
            CouponBatch::STATUS_ACTIVE => 'Live',
            CouponBatch::STATUS_FULL => 'Reached its limit',
            CouponBatch::STATUS_KILLED => 'Stopped',
            default => 'Expired',
        };
    }

    /**
     * COUP-13: the quiet zero-claim line, in exactly its named
     * numeric states (batch 7 or more days old; scans without
     * claims, or no scans at all). Never a push, never advice.
     *
     * @param  array{scans: int, claimed: int, redeemed: int}  $funnel
     */
    private function zeroLine(CouponBatch $batch, array $funnel): ?string
    {
        if ($batch->created_at === null || $batch->created_at->copy()->addDays(7)->isFuture()) {
            return null;
        }

        if ($funnel['claimed'] > 0) {
            return null;
        }

        return $funnel['scans'] === 0
            ? 'No scans yet. If the flyers are not out yet, this is expected.'
            : 'People are opening it but not claiming yet. That is normal in the first days.';
    }

    /**
     * COUP-12: the paused banner, one form per state, because
     * one shared sentence would be false in at least one of
     * them. Null while couponFlowActive holds.
     */
    private function pausedBanner($business): ?string
    {
        if ($business->couponFlowActive()) {
            return null;
        }

        if ($business->isClosed()) {
            $end = $business->closed_at !== null
                ? $business->localTime($business->closed_at->copy()->addDays(30))->format('F j, Y')
                : '';

            return 'Your program is closed, so no new coupons can be claimed. Coupons already claimed stay good until '.$end.'.';
        }

        if ($business->billing_status === 'past_due') {
            return 'New coupons are paused until your payment is sorted out. Coupons already claimed can still be used at your counter until their dates run out.';
        }

        return 'New coupons are paused because your program is not active right now. Coupons already claimed can still be used at your counter until their dates run out. Fix it in Billing.';
    }

    /** AHA-COUP-03 ideas, swapped by trade where the pass names one. */
    private function ideasFor($business): string
    {
        return match ($business->business_type) {
            'barber' => "Three starts for a barbershop: Print 10 and ask the gym if you can leave them by the front desk, right after workout hours is when a cut is on people's minds. Put 5 at the laundromat board: people waiting on a cycle have time to look. Leave a stack with the cafe on the corner and take theirs in return: their morning regulars are your afternoon walk-ins.",
            'cafe' => "Three starts for a cafe: Print 10 and ask the gym if you can leave them by the door: your shop is the stop after the workout. Put 5 on the station or bus stop board, where the morning crowd stands still for two minutes. Ask the barbershop and the salon on your street to swap stacks: people with time booked in a chair nearby are people who will want a coffee while they wait.",
            default => 'New to this? Three starts that work for most shops: Print 10 and ask the gym and the laundromat if you can leave them at the counter. Put 5 on the community board at the library or the station. Ask a nearby shop that is not a competitor to swap: their flyers by your register, yours by theirs.',
        };
    }
}
