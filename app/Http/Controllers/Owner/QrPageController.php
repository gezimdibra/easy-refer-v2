<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\QrCode;
use App\Models\ReferralEvent;
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
    public function index(Request $request): View
    {
        $business = $request->user()->business;
        $qr = $this->liveCode($business);

        return view('owner.qr', [
            'business' => $business,
            'qr' => $qr,
            'publicUrl' => route('refer.landing', ['token' => $qr->plainToken()]),
            'imageUrl' => route('owner.qr.image'),
        ]);
    }

    public function image(Request $request): Response
    {
        $business = $request->user()->business;
        $qr = $this->liveCode($business);

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
     * poster per placement, sized for its use. Opening a poster is
     * a download: it records the same poster_downloaded event the
     * Overview checklist reads.
     */
    public function poster(Request $request, string $variant): View
    {
        abort_unless(in_array($variant, ['screen', 'counter', 'window'], true), 404);

        $business = $request->user()->business;
        $this->liveCode($business);

        ReferralEvent::record('poster_downloaded', 'owner', $business);
        AuditLog::write('qr.poster_downloaded', $request->user(), ['variant' => $variant], $business);

        return view('owner.poster', [
            'business' => $business,
            'variant' => $variant,
            'imageUrl' => route('owner.qr.image'),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /**
     * Replace the live code (spec 3.1): the current code is
     * revoked and a new one issued, so printed copies of the old
     * code stop working right away. Owner-initiated from the
     * danger zone, behind its consequence modal (F-10).
     */
    public function regenerate(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        $current = $business->activeQrCode();

        if ($current !== null) {
            $current->forceFill(['state' => 'revoked', 'revoked_at' => now()])->save();
        }

        QrCode::issueFor($business);

        AuditLog::write('qr.regenerated', $request->user(), [], $business);

        return redirect()->route('owner.qr')->with('status', 'Your new QR code is ready. Print new posters to replace the old ones.');
    }

    private function liveCode(\App\Models\Business $business): QrCode
    {
        $qr = $business->activeQrCode();

        if ($qr !== null && $qr->plainToken() !== null) {
            return $qr;
        }

        if ($qr !== null) {
            $qr->forceFill(['state' => 'revoked', 'revoked_at' => now()])->save();
        }

        // issueFor returns the one-time plaintext; the stored row is
        // the handle this page renders from.
        QrCode::issueFor($business);

        return $business->activeQrCode();
    }
}
