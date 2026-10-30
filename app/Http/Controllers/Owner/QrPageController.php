<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\QrCode;
use App\Models\ReferralEvent;
use App\Services\Owner\PlacementCoaching;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Support\QrImage;
use Illuminate\View\View;

/**
 * The owner's QR page (spec Section 5.4, Sprint 2 bridge): the
 * live code, its public link, and the download action. The full
 * designed poster set lands in Sprint 3; this page exists now so
 * the Overview's primary action is never a dead control (AP-10).
 * The public link is printed from the sealed token copy; a code
 * issued before sealed copies existed is revoked and reissued
 * here, once, so the page always shows a working code.
 */
class QrPageController extends Controller
{
    /**
     * Poster variant -> the placement whose code it prints
     * (QRK-05/07; kit §4: a poster prints the code for its
     * placement). Counter and mini both live at the counter;
     * the tent is the table placement's poster; the receipt
     * card prints at the register. (Amendment 31 [ON-QR-1]:
     * the screen variant is retired and no longer resolves.)
     */
    private const VARIANT_PLACEMENT = [
        'counter' => 'counter',
        'mini' => 'counter',
        'window' => 'window',
        'tent' => 'table',
        'receipt' => 'receipt',
    ];

    /** Placement -> the poster its panel Print action opens. */
    private const PLACEMENT_POSTER = [
        'counter' => 'counter',
        'window' => 'window',
        'table' => 'tent',
        'receipt' => 'receipt',
    ];

    public function index(Request $request, PlacementCoaching $coaching, \App\Services\Coupons\CouponService $coupons): View
    {
        $business = $request->user()->business;

        // ID-11 benefit hint (Amendment 53, HINT-COUP-01): the
        // one hint on this surface, under the codes area. It
        // shows until the owner creates a first batch or
        // dismisses it, then never again (CouponService carries
        // the rule). The shown stamp is recorded at most once
        // per business per day, so the hint funnel has a
        // denominator without an event per page load.
        $couponHint = $coupons->hintVisible($business, $request->user());
        if ($couponHint) {
            $shownToday = ReferralEvent::query()
                ->where('business_id', $business->id)
                ->where('event_type', 'coupon_hint_shown')
                ->where('created_at', '>=', now()->startOfDay())
                ->exists();
            if (! $shownToday) {
                ReferralEvent::record('coupon_hint_shown', 'owner', $business);
            }
        }
        $codes = QrCode::ensurePlacements($business);
        $qr = $codes['counter'];
        $stats = $this->placementStats($business);

        $placements = [];
        foreach (QrCode::PLACEMENTS as $placement) {
            // SA-06 (Amendment 41): the row carries the spot's
            // age and its last scan, both business-local, so a
            // silent spot reads differently on day 1 and day 90.
            // Staleness is measured from the later of the code's
            // added date and its last scan: 30+ days with no scan
            // is a dead spot. A replaced code is a fresh code, so
            // its dates (and its staleness) follow the placement's
            // CURRENT code, per the r30 replace inheritance.
            $lastScanAt = $stats[$placement]['last_scan_at'];
            $silentSince = $lastScanAt;
            $codeCreatedAt = $codes[$placement]->created_at;
            if ($codeCreatedAt !== null && ($silentSince === null || $codeCreatedAt->gt($silentSince))) {
                $silentSince = $codeCreatedAt;
            }
            $placements[] = [
                'key' => $placement,
                // [ON-SPOT-2]: the displayed name is the active
                // code's display name (owner-set label, else the
                // format name), resolved in one place (QrCode).
                'name' => $codes[$placement]->displayName(),
                'defaultName' => QrCode::formatNameForPlacement($placement),
                'code' => $codes[$placement],
                'added' => $business->localTime($codes[$placement]->created_at)->format('F j, Y'),
                'lastScan' => $lastScanAt !== null
                    ? $business->localTime($lastScanAt)->format('F j, Y')
                    : null,
                'stale' => $silentSince !== null && $silentSince->copy()->addDays(30)->isPast(),
                'scans' => $stats[$placement]['scans'],
                'joins' => $stats[$placement]['joins'],
                'posterVariant' => self::PLACEMENT_POSTER[$placement],
            ];
        }

        // ID-04 placement coaching (Variant B): one comparison
        // card above Print by spot, rendered only when the full
        // floor holds and no suppression holds (PlacementCoaching
        // carries the whole rule). The rows' own stale flags are
        // handed over so the SA-06 precedence check reads the
        // same staleness derivation the rows render. A rendered
        // card records its impression (the measurement log).
        $staleMap = [];
        foreach ($placements as $row) {
            $staleMap[$row['key']] = $row['stale'];
        }
        $coachingCard = $coaching->cardFor($business, $staleMap);
        if ($coachingCard !== null) {
            $coachingCard['actionUrl'] = route('owner.qr.poster', array_filter([
                'variant' => $coachingCard['action_variant'],
                'print' => 1,
                'multi' => $coachingCard['action_multi'] ? 1 : null,
            ]));
            $coaching->recordImpression($business, $coachingCard);
        }

        return view('owner.qr', [
            'business' => $business,
            'qr' => $qr,
            'placements' => $placements,
            'couponHint' => $couponHint,
            'coaching' => $coachingCard,
            'publicUrl' => route('refer.landing', ['token' => $qr->plainToken()]),
            'imageUrl' => route('owner.qr.image'),
        ]);
    }

    /**
     * Dismiss the coaching card (ID-04, COACH-05 "Not now"): the
     * dismissal is recorded against the comparison the server
     * computes right now, never against client-posted state, so
     * a stale or invented post records the live fingerprint or
     * nothing. With no live card there is nothing to dismiss.
     */
    public function dismissCoaching(Request $request, PlacementCoaching $coaching): RedirectResponse
    {
        $business = $request->user()->business;
        $candidate = $coaching->assess($business);

        if ($candidate !== null) {
            $coaching->recordDismissal($business, $candidate);
        }

        return redirect()->route('owner.qr');
    }

    /**
     * Per-placement scan and join counts for the panel (QRK-07),
     * derived from the scan events QRK-05 stamps: scans are the
     * events per placement; joins are the distinct memberships
     * among them (a join IS an enrollment, and the scan event is
     * written by the enrollment that completes it). Events with
     * no placement in their metadata predate placements and
     * belong to the store's original code: the counter.
     *
     * @return array<string, array{scans: int, joins: int, last_scan_at: ?\Illuminate\Support\Carbon}>
     */
    private function placementStats(\App\Models\Business $business): array
    {
        $stats = [];
        foreach (QrCode::PLACEMENTS as $placement) {
            $stats[$placement] = ['scans' => 0, 'joins' => [], 'last_scan_at' => null];
        }

        $events = ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'scan')
            ->get(['membership_id', 'metadata', 'created_at']);

        foreach ($events as $event) {
            $placement = $event->metadata['placement'] ?? 'counter';
            if (! isset($stats[$placement])) {
                $placement = 'counter';
            }
            $stats[$placement]['scans']++;
            if ($event->membership_id !== null) {
                $stats[$placement]['joins'][$event->membership_id] = true;
            }
            if ($event->created_at !== null
                && ($stats[$placement]['last_scan_at'] === null || $event->created_at->gt($stats[$placement]['last_scan_at']))) {
                $stats[$placement]['last_scan_at'] = $event->created_at;
            }
        }

        foreach ($stats as $placement => $row) {
            $stats[$placement]['joins'] = count($row['joins']);
        }

        return $stats;
    }

    public function image(Request $request): Response
    {
        $business = $request->user()->business;
        $qr = $this->liveCode($business);

        return response(QrImage::svg(route('refer.landing', ['token' => $qr->plainToken()])), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    /**
     * One placement's code image (QRK-07): posters print the
     * code for their placement, so the window poster carries
     * the window code, the tent the table code, and so on.
     */
    public function imagePlacement(Request $request, string $placement): Response
    {
        abort_unless(in_array($placement, QrCode::PLACEMENTS, true), 404);

        $business = $request->user()->business;
        $qr = $this->livePlacementCode($business, $placement);

        return response(QrImage::svg(route('refer.landing', ['token' => $qr->plainToken()])), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    public function download(Request $request): Response
    {
        $business = $request->user()->business;
        $qr = $this->liveCode($business);

        ReferralEvent::record('poster_downloaded', 'owner', $business);
        AuditLog::write('qr.poster_downloaded', $request->user(), [], $business);

        // T2-F3: the button promises a download, so the file is
        // served as an attachment. (It used to redirect to the
        // inline image route, and the browser just displayed the
        // bare SVG.) The image route itself stays inline: the
        // page's <img> tags display from it.
        return response(QrImage::svg(route('refer.landing', ['token' => $qr->plainToken()])), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="easy-refer-qr-code.svg"',
        ]);
    }

    /**
     * The designed poster set (design 3.4, DASH-08): one printable
     * poster per placement, sized for its use. The checklist's
     * poster step completes when the owner PRINTS or DOWNLOADS
     * the artifact (coordinator ruling Q2 on the coherence
     * audit's G9) — the observable act of taking it — so the
     * poster_downloaded event is recorded only on a print-mode
     * open (every poster link in the product carries print=1)
     * or by the download action above. A bare view records
     * nothing: it neither finishes the step nor triggers
     * DG-2's dashboard demotion.
     */
    public function poster(Request $request, string $variant): View
    {
        abort_unless(in_array($variant, ['counter', 'window', 'mini', 'tent', 'receipt'], true), 404);

        $business = $request->user()->business;
        $placement = self::VARIANT_PLACEMENT[$variant];
        $this->livePlacementCode($business, $placement);

        if ($request->boolean('print')) {
            ReferralEvent::record('poster_downloaded', 'owner', $business);
            AuditLog::write('qr.poster_downloaded', $request->user(), ['variant' => $variant], $business);
        }

        return view('owner.poster', [
            'business' => $business,
            'variant' => $variant,
            // QRK-07: the poster prints its own placement's code.
            'imageUrl' => route('owner.qr.image.placement', ['placement' => $placement]),
            // QRK-06 (REF-POSTER-02j): the typed fallback address,
            // rendered from the app's own address at print time
            // (the one config point), without the scheme, so the
            // printed line always resolves from this deployment.
            'joinDisplay' => preg_replace('#^https?://#', '', route('join.show', ['slug' => $business->ensureJoinSlug()])),
            'autoPrint' => $request->boolean('print'),
            // RC-3 Candidate F (Amendment 41): the receipt
            // variant's multi-up sheet of cards (multi=1), the
            // supply form that makes "print a stack" true. The
            // card composition itself is shared, never forked.
            'multiUp' => $variant === 'receipt' && $request->boolean('multi'),
            // MIX-1: the QR page's Start here panel previews the
            // business's own counter poster as a scaled live
            // render of this same view; preview mode strips the
            // screen chrome so only the sheet shows. A preview
            // open records nothing (only print=1 completes the
            // checklist's poster step).
            'preview' => $request->boolean('preview'),
        ]);
    }

    /**
     * Replace ALL codes at once (QRK-07; REF-POSTER-03c): every
     * printed code for the store stops working and one fresh
     * code per placement is issued, in one transaction
     * (QrCode::replaceAllPlacements). Owner-initiated from the
     * danger zone, behind its consequence modal (F-10). This is
     * the route the pre-placement "Replace this code" action
     * used; with placements, replace means all of them.
     */
    public function regenerate(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        QrCode::replaceAllPlacements($business);

        AuditLog::write('qr.regenerated', $request->user(), ['scope' => 'all'], $business);

        return redirect()->route('owner.qr')->with('status', 'All new codes are ready. Print new posters to replace the old ones.');
    }

    /**
     * Replace ONE placement's code (QRK-05; REF-POSTER-03b): the
     * placement's current code is revoked and a fresh one issued
     * in one transaction (QrCode::replacePlacement, the FB-01
     * pattern); the other placements' codes keep working.
     */
    public function regeneratePlacement(Request $request, string $placement): RedirectResponse
    {
        abort_unless(in_array($placement, QrCode::PLACEMENTS, true), 404);

        $business = $request->user()->business;

        // [ON-SPOT-3]: the flash reads the code's current
        // display name (the replacement inherits the name, so
        // the name is the same before and after the replace).
        $displayName = QrCode::activeForPlacement($business, $placement)?->displayName()
            ?? QrCode::formatNameForPlacement($placement);

        QrCode::replacePlacement($business, $placement);

        AuditLog::write('qr.regenerated', $request->user(), ['placement' => $placement], $business);

        return redirect()->route('owner.qr')
            ->with('status', "New code is ready for {$displayName}. Print a new poster for that spot.");
    }

    /**
     * Rename one placement's active code (Amendment 31
     * [ON-SPOT-3]): the name is a label on the active code row
     * (QrCode.label) and nothing else — posters, tokens, and
     * scan counting (which keys on the placement) never read
     * it. An empty name clears the label, restoring the format
     * default. The only invalid input is length; its error
     * lands in the per-placement error bag so the row's own
     * field renders it.
     */
    public function renamePlacement(Request $request, string $placement): RedirectResponse
    {
        abort_unless(in_array($placement, QrCode::PLACEMENTS, true), 404);

        $data = $request->validateWithBag('rename_'.$placement, [
            'name' => ['nullable', 'string', 'max:40'],
        ], [
            'name.max' => 'Keep the name under 40 characters.',
        ]);

        $business = $request->user()->business;
        $code = QrCode::ensurePlacements($business)[$placement];

        $name = trim((string) ($data['name'] ?? ''));
        $code->forceFill(['label' => $name === '' ? null : $name])->save();

        AuditLog::write('qr.placement_renamed', $request->user(), ['placement' => $placement], $business);

        return redirect()->route('owner.qr')->with('status', 'Code renamed.');
    }

    private function liveCode(\App\Models\Business $business): QrCode
    {
        $qr = $business->activeQrCode();

        if ($qr !== null && $qr->plainToken() !== null) {
            return $qr;
        }

        // [ON-SPOT-3]: a reissue inherits the revoked code's name.
        $label = $qr?->label;

        if ($qr !== null) {
            $qr->forceFill(['state' => 'revoked', 'revoked_at' => now()])->save();
        }

        // issueFor returns the one-time plaintext; the stored row is
        // the handle this page renders from.
        QrCode::issueFor($business, $label);

        return $business->activeQrCode();
    }

    /**
     * The liveCode guarantee, per placement (QRK-07): an active
     * code for the placement with its sealed copy present,
     * reissuing once when the stored row cannot be re-presented.
     */
    private function livePlacementCode(\App\Models\Business $business, string $placement): QrCode
    {
        $qr = QrCode::activeForPlacement($business, $placement);

        if ($qr !== null && $qr->plainToken() !== null) {
            return $qr;
        }

        // [ON-SPOT-3]: a reissue inherits the revoked code's name.
        $label = $qr?->label;

        if ($qr !== null) {
            $qr->forceFill(['state' => 'revoked', 'revoked_at' => now()])->save();
        }

        QrCode::issueFor($business, $label, $placement);

        return QrCode::activeForPlacement($business, $placement);
    }
}
