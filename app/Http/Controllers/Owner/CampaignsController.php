<?php

namespace App\Http\Controllers\Owner;

use App\Contracts\MarketingSmsGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\StoreCampaignRequest;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Rules\CampaignBodyLength;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignSender;
use App\Services\Referral\MessageCopy;
use App\Services\Twilio\NullMarketingSmsGateway;
use App\Support\ActionToast;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bulk SMS campaigns (spec Sections 10.3, 10.4; inventory
 * DASH-09, DASH-10). Thin, in the shape of the other owner
 * controllers: every action scopes by the signed-in business
 * and another business's campaign is a 404, the same answer a
 * stranger gets. There is no one-click send: compose saves a
 * draft, the confirm screen is the only send path, and the send
 * recomputes the audience from ground truth (CampaignSender).
 */
class CampaignsController extends Controller
{
    /** Twilio error codes rendered as plain words on the detail page. */
    private const FAILURE_REASONS = [
        '21610' => 'This number opted out of texts.',
        '21211' => 'This number is not a valid mobile number.',
        '21614' => 'This number cannot receive texts.',
        '21408' => 'Sending to this region is not permitted.',
        '30003' => 'The phone was unreachable.',
        '30005' => 'This number is unknown or no longer in service.',
        '30006' => 'This number is a landline or cannot receive texts.',
        '30007' => 'The carrier filtered this message.',
    ];

    public function index(Request $request): View
    {
        $business = $request->user()->business;

        $campaigns = SmsCampaign::query()
            ->where('business_id', $business->id)
            ->withCount([
                'recipients as delivered_count' => fn ($query) => $query->where('delivery_state', SmsCampaignRecipient::STATE_DELIVERED),
                'recipients as failed_count' => fn ($query) => $query->whereIn('delivery_state', [SmsCampaignRecipient::STATE_FAILED, SmsCampaignRecipient::STATE_UNDELIVERED]),
            ])
            ->orderByDesc('id')
            ->paginate(25);

        return view('owner.campaigns.index', [
            'business' => $business,
            'campaigns' => $campaigns,
            'available' => $this->available(),
        ]);
    }

    public function create(Request $request): View
    {
        $business = $request->user()->business;

        if (! $this->available()) {
            return view('owner.campaigns.create', [
                'business' => $business,
                'available' => false,
            ]);
        }

        $draft = null;
        if ($request->filled('draft')) {
            $draft = SmsCampaign::query()
                ->where('business_id', $business->id)
                ->where('status', SmsCampaign::STATUS_DRAFT)
                ->find((int) $request->query('draft'));
        }

        $audience = CampaignAudience::forBusiness($business);
        $figures = app(CampaignSender::class)->figures($business, $draft?->body ?? '');

        return view('owner.campaigns.create', [
            'business' => $business,
            'available' => true,
            'draft' => $draft,
            'audience' => $audience,
            'figures' => $figures,
            'estimateUrl' => route('campaigns.estimate'),
            'presets' => \App\Support\CampaignPresets::all(),
        ]);
    }

    /**
     * Start from a ready-made message (LV-01; CAMP-16..19): a
     * preset creates a draft with the preset body and lands on
     * the confirm screen, where the estimate and preflight
     * render before anything sends. The bodies carry no locked
     * prefix or STOP suffix; those are system parts.
     */
    public function preset(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        if (! $this->available()) {
            return redirect()->route('campaigns.index')
                ->with('status', 'Text campaigns are not available right now.');
        }

        $validated = $request->validate([
            'preset' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Support\CampaignPresets::all()))],
        ]);

        $preset = \App\Support\CampaignPresets::find($validated['preset']);

        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => $preset['body'],
            'status' => SmsCampaign::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        app(CampaignSender::class)->stampFigures($campaign);

        return redirect()->route('campaigns.confirm', $campaign);
    }

    /**
     * Send something like this again (LV-01; CAMP-20): a sent
     * campaign's body becomes a fresh draft. Only the body
     * carries over — the audience re-derives from the
     * eligibility rule at compose, confirm, and send, never
     * from the original's recipient rows.
     */
    public function duplicate(Request $request, int $campaign): RedirectResponse
    {
        $business = $request->user()->business;

        if (! $this->available()) {
            return redirect()->route('campaigns.index')
                ->with('status', 'Text campaigns are not available right now.');
        }

        $original = SmsCampaign::query()
            ->where('business_id', $business->id)
            ->where('status', SmsCampaign::STATUS_SENT)
            ->findOrFail($campaign);

        $copy = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => $original->body,
            'status' => SmsCampaign::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        app(CampaignSender::class)->stampFigures($copy);

        return redirect()->route('campaigns.confirm', $copy);
    }

    public function estimate(Request $request, CampaignSender $sender): JsonResponse
    {
        $business = $request->user()->business;
        // The estimate validates with the store's own length
        // rule (FB-03): the counter must never price a body the
        // store would refuse.
        $validated = $request->validate([
            'body' => ['present', 'string', new CampaignBodyLength],
        ]);

        $figures = $sender->figures($business, (string) $validated['body']);

        return response()->json([
            'rendered_body' => $figures['rendered_body'],
            'segments' => $figures['segments'],
            'encoding' => $figures['encoding'],
            'per_recipient_cents' => $figures['per_recipient_cents'],
            'recipients' => $figures['recipients'],
            'total_cents' => $figures['total_cents'],
        ]);
    }

    public function store(StoreCampaignRequest $request, CampaignSender $sender): RedirectResponse
    {
        $business = $request->user()->business;

        if (! $this->available()) {
            return redirect()->route('campaigns.index')
                ->with('status', 'Text campaigns are not available right now.');
        }

        $campaign = null;
        if ($request->filled('draft_id')) {
            $campaign = SmsCampaign::query()
                ->where('business_id', $business->id)
                ->where('status', SmsCampaign::STATUS_DRAFT)
                ->find((int) $request->input('draft_id'));
        }

        if ($campaign === null) {
            $campaign = SmsCampaign::create([
                'business_id' => $business->id,
                'body' => (string) $request->input('body'),
                'status' => SmsCampaign::STATUS_DRAFT,
                'created_by' => $request->user()->id,
            ]);
        } else {
            $campaign->forceFill(['body' => (string) $request->input('body')])->save();
        }

        $sender->stampFigures($campaign);

        return redirect()->route('campaigns.confirm', $campaign);
    }

    public function confirm(Request $request, int $campaign, CampaignSender $sender): View|RedirectResponse
    {
        $business = $request->user()->business;
        $campaign = $this->scoped($request, $campaign);

        if ($campaign->status !== SmsCampaign::STATUS_DRAFT) {
            return redirect()->route('campaigns.show', $campaign);
        }

        if (! $this->available()) {
            return redirect()->route('campaigns.index')
                ->with('status', 'Text campaigns are not available right now.');
        }

        // The confirm render recomputes from ground truth and
        // stamps the draft, so the send's comparison is against
        // exactly what the owner approved here.
        $sender->stampFigures($campaign);
        $figures = $sender->figures($business, $campaign->body);
        $audience = CampaignAudience::forBusiness($business);

        return view('owner.campaigns.confirm', [
            'business' => $business,
            'campaign' => $campaign->fresh(),
            'figures' => $figures,
            'audience' => $audience,
            'segmentPriceCents' => (int) config('billing.sms_segment_price_cents'),
        ]);
    }

    public function send(Request $request, int $campaign, CampaignSender $sender): RedirectResponse
    {
        $campaign = $this->scoped($request, $campaign);

        if (! $this->available()) {
            return redirect()->route('campaigns.index')
                ->with('status', 'Text campaigns are not available right now.');
        }

        $result = $sender->send($campaign);

        return match ($result['outcome']) {
            'bounced' => redirect()->route('campaigns.confirm', $campaign)
                ->with('status', 'The numbers changed since you last saw them. Check the new figures, then send again.'),
            'paused' => redirect()->route('campaigns.confirm', $campaign)
                ->with('status', 'Text campaigns are paused right now. Please try again tomorrow.'),
            default => $this->sentRedirect($request, $campaign),
        };
    }

    /**
     * RS-14 (GEN-04): a sent campaign's landing carries the
     * outcome toast — the count the send itself stamped, and a
     * View results link to the campaign's page.
     */
    private function sentRedirect(Request $request, SmsCampaign $campaign): RedirectResponse
    {
        $message = 'Campaign sent to '.(int) ($campaign->fresh()->recipient_count ?? 0).' customers.';

        ActionToast::outcome($request, $message, 'View results', route('campaigns.show', $campaign));

        return redirect()->route('campaigns.show', $campaign)
            ->with('status', $message);
    }

    public function show(Request $request, int $campaign): View
    {
        $campaign = $this->scoped($request, $campaign);

        $counts = $this->stateCounts($campaign);
        $total = (int) array_sum($counts);
        $processed = $total - $counts[SmsCampaignRecipient::STATE_QUEUED]
            - $counts[SmsCampaignRecipient::STATE_NOT_SENT]
            - $counts[SmsCampaignRecipient::STATE_SKIPPED];

        $recipients = $campaign->recipients()
            ->with('customer')
            ->orderBy('id')
            ->paginate(25);

        return view('owner.campaigns.show', [
            'business' => $request->user()->business,
            'campaign' => $campaign,
            'renderedBody' => MessageCopy::campaign($campaign->business, $campaign->body),
            'counts' => $counts,
            'total' => $total,
            'processed' => $processed,
            'recipients' => $recipients,
            'mask' => fn (string $phone) => PhoneNumber::mask($phone),
            'failureReason' => fn (?string $code) => self::FAILURE_REASONS[$code ?? '']
                ?? 'The message could not be delivered.',
        ]);
    }

    public function status(Request $request, int $campaign): JsonResponse
    {
        $campaign = $this->scoped($request, $campaign);
        $counts = $this->stateCounts($campaign);

        return response()->json([
            'status' => $campaign->status,
            'total' => (int) array_sum($counts),
            'counts' => $counts,
        ]);
    }

    public function destroy(Request $request, int $campaign): RedirectResponse
    {
        $campaign = SmsCampaign::query()
            ->where('business_id', $request->user()->business->id)
            ->where('status', SmsCampaign::STATUS_DRAFT)
            ->findOrFail($campaign);

        $campaign->delete();

        return redirect()->route('campaigns.index')
            ->with('status', 'Draft discarded.');
    }

    /**
     * Another business's campaign is a 404, the same answer a
     * stranger gets (the statement-page convention).
     */
    private function scoped(Request $request, int $campaign): SmsCampaign
    {
        return SmsCampaign::query()
            ->where('business_id', $request->user()->business->id)
            ->findOrFail($campaign);
    }

    /**
     * Campaign surfaces render closed-but-graceful while the
     * marketing gateway is unconfigured: never a 500, never a
     * half-send.
     */
    private function available(): bool
    {
        return ! app(MarketingSmsGateway::class) instanceof NullMarketingSmsGateway;
    }

    /**
     * @return array<string, int>
     */
    private function stateCounts(SmsCampaign $campaign): array
    {
        $counts = array_fill_keys([
            SmsCampaignRecipient::STATE_QUEUED,
            SmsCampaignRecipient::STATE_SENT,
            SmsCampaignRecipient::STATE_DELIVERED,
            SmsCampaignRecipient::STATE_FAILED,
            SmsCampaignRecipient::STATE_UNDELIVERED,
            SmsCampaignRecipient::STATE_SKIPPED,
            SmsCampaignRecipient::STATE_NOT_SENT,
        ], 0);

        foreach ($campaign->recipients()->selectRaw('delivery_state, COUNT(*) as total')->groupBy('delivery_state')->pluck('total', 'delivery_state') as $state => $count) {
            $counts[$state] = (int) $count;
        }

        return $counts;
    }
}
