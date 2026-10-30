<?php

namespace App\Http\Controllers\Owner;

use App\Contracts\MarketingSmsGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\StoreCampaignRequest;
use App\Models\Business;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Rules\CampaignBodyLength;
use App\Models\AuditLog;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignResults;
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
                // FE-CAMP-01: the recipients no column used to
                // account for — skipped at the send re-check or
                // recorded not-sent by the daily cap — so the
                // columns sum to the recipient count.
                'recipients as skipped_count' => fn ($query) => $query->whereIn('delivery_state', [SmsCampaignRecipient::STATE_SKIPPED, SmsCampaignRecipient::STATE_NOT_SENT]),
            ])
            // R2 CAMP-IDX: drafts lead the card list (they are
            // the unfinished work); everything else stays
            // newest first.
            ->orderByRaw("case when status = 'draft' then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate(25);

        return view('owner.campaigns.index', [
            'business' => $business,
            'campaigns' => $campaigns,
            'available' => $this->available(),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
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

            // ID-02 (TR-11 guardrail): the generic composer
            // NEVER opens a recognition draft — its audience is
            // the whole list, and a personalised body sent to
            // the whole list would be false for every other
            // recipient. The scoped editor is the only door.
            if ($draft !== null && $draft->isRecognition()) {
                return redirect()->route('campaigns.recognition.edit', $draft);
            }
        }

        // ID-03: a segment draft's composer prices and counts
        // its SEGMENT audience (the estimate endpoint does the
        // same via draft_id); a manual draft prices the whole
        // list, exactly as before.
        $segment = $draft?->audience_segment;
        $audience = CampaignAudience::forBusiness($business, $segment);
        $figures = app(CampaignSender::class)->figures($business, $draft?->body ?? '', $segment);

        // The win-back segment line (WB-03..WB-06) reads the
        // segment audience whether or not a segment draft is
        // open; when one is, it is the same read.
        $segmentAudience = $segment === SmsCampaign::SEGMENT_LAPSED_REFERRERS
            ? $audience
            : CampaignAudience::forBusiness($business, SmsCampaign::SEGMENT_LAPSED_REFERRERS);

        // ID-01: the dormant analyzer runs and is recorded on
        // this page's render. Its result is deliberately NOT
        // passed to the view: it surfaces nothing in this
        // build (the invisibility pin, Amendment 46).
        \App\Services\Campaigns\SlowDayAnalyzer::analyzeAndRecord($business);

        return view('owner.campaigns.create', [
            'business' => $business,
            'available' => true,
            'draft' => $draft,
            'audience' => $audience,
            'figures' => $figures,
            'segmentAudience' => $segmentAudience,
            'flowState' => $this->flowState($business),
            'estimateUrl' => route('campaigns.estimate'),
            'presets' => \App\Support\CampaignPresets::all(),
        ]);
    }

    /**
     * The quiet-day card's save (ID-01 Phase 1, SD-06/SD-07):
     * the owner's own declaration — a weekday pick or none —
     * plus the reminders re-enable. Picking a day is a
     * preference; the prompt gates, not this card, decide
     * whether a prompt ever fires.
     */
    public function quietDay(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        $validated = $request->validate([
            'slow_day_weekday' => ['nullable', 'integer', 'min:0', 'max:6'],
            'reminders_on' => ['nullable', 'boolean'],
        ]);

        $business->forceFill([
            'slow_day_weekday' => $validated['slow_day_weekday'] ?? null,
        ])->save();

        if (! empty($validated['reminders_on'])) {
            \App\Services\Campaigns\CampaignPrompts::reenable($business, \App\Services\Campaigns\CampaignPrompts::ITEM_SLOW_DAY);
        }

        return redirect()->route('campaigns.create')
            ->with('status', 'Your quiet day is saved.');
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
            // A prompt origin is accepted only from the Action
            // Center's prompt forms, and only for its own
            // preset; anything else falls back to the preset's
            // own origin.
            'origin' => ['nullable', 'string', \Illuminate\Validation\Rule::in(['slow_day_prompt', 'winback_prompt'])],
        ]);

        $preset = \App\Support\CampaignPresets::find($validated['preset']);

        $origin = $preset['origin'];
        $promptItem = null;
        if (($validated['origin'] ?? null) === 'slow_day_prompt' && $validated['preset'] === 'slow_day') {
            $origin = 'slow_day_prompt';
            $promptItem = \App\Services\Campaigns\CampaignPrompts::ITEM_SLOW_DAY;
        }
        if (($validated['origin'] ?? null) === 'winback_prompt' && $validated['preset'] === 'win_back_referrers') {
            $origin = 'winback_prompt';
            $promptItem = \App\Services\Campaigns\CampaignPrompts::ITEM_WINBACK;
        }

        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => $preset['body'],
            'audience_segment' => $preset['audience_segment'],
            'origin' => $origin,
            'status' => SmsCampaign::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        if ($promptItem !== null) {
            \App\Services\Campaigns\CampaignPrompts::recordActed($business, $promptItem, $campaign->id);
        }

        app(CampaignSender::class)->stampFigures($campaign);

        return redirect()->route('campaigns.confirm', $campaign);
    }

    /**
     * ID-02 (Amendment 48): the Action Center card's action.
     * Creates one recipient-locked recognition draft per
     * unsent leader from the CURRENT standings read (the click
     * never sends) and lands on the first draft's confirm. A
     * posted month that is no longer the current window month
     * creates nothing.
     */
    public function recognition(Request $request): RedirectResponse
    {
        $business = $request->user()->business;

        if (! $this->available()) {
            return redirect()->route('campaigns.index')
                ->with('status', 'Text campaigns are not available right now.');
        }

        $month = (string) $request->input('standings_month', '');

        if (\App\Services\Campaigns\TopReferrerStandings::currentStandingsMonth($business) !== $month) {
            return redirect()->route('actions.index')
                ->with('status', 'That thank-you month has closed.');
        }

        $standings = \App\Services\Campaigns\TopReferrerStandings::leadersForMonth($business, $month);

        if ($standings === null) {
            return redirect()->route('actions.index')
                ->with('status', 'There is no top referrer to thank right now.');
        }

        $first = null;
        foreach ($standings['leaders'] as $leader) {
            if (\App\Services\Campaigns\TopReferrerStandings::alreadySent($business, $month, $leader->id)) {
                continue;
            }

            $existing = SmsCampaign::query()
                ->where('business_id', $business->id)
                ->where('provenance', SmsCampaign::PROVENANCE_TOP_REFERRER)
                ->where('standings_month', $month)
                ->where('recipient_membership_id', $leader->id)
                ->where('status', SmsCampaign::STATUS_DRAFT)
                ->first();

            if ($existing !== null) {
                $first ??= $existing;

                continue;
            }

            $draft = SmsCampaign::create([
                'business_id' => $business->id,
                'body' => \App\Services\Campaigns\TopReferrerStandings::templateBody($business, $leader, $month, $standings['count'], $standings['form']),
                'provenance' => SmsCampaign::PROVENANCE_TOP_REFERRER,
                'recipient_membership_id' => $leader->id,
                'standings_month' => $month,
                'standings_count' => $standings['count'],
                'standings_form' => $standings['form'],
                'body_customized' => false,
                'origin' => 'top_referrer_prompt',
                'status' => SmsCampaign::STATUS_DRAFT,
                'created_by' => $request->user()->id,
            ]);

            app(CampaignSender::class)->stampFigures($draft);
            $first ??= $draft;
        }

        if ($first === null) {
            return redirect()->route('actions.index')
                ->with('status', 'That thank-you was already sent.');
        }

        \App\Services\Campaigns\CampaignPrompts::recordActed($business, \App\Services\Campaigns\CampaignPrompts::ITEM_TOP_REFERRER, $first->id);

        return redirect()->route('campaigns.confirm', $first);
    }

    /**
     * ID-02: the scoped body-only editor for a recognition
     * draft (TR-11 head). The only edit surface a recognition
     * draft has. Saving marks the body customised unless it
     * matches the current template exactly; "Use the updated
     * text" regenerates from the live standings.
     */
    public function recognitionEdit(Request $request, SmsCampaign $campaign): View|RedirectResponse
    {
        $campaign = $this->scoped($request, $campaign);

        if (! $campaign->isRecognition() || $campaign->status !== SmsCampaign::STATUS_DRAFT) {
            return redirect()->route('campaigns.show', $campaign);
        }

        $fingerprint = \App\Services\Campaigns\TopReferrerStandings::fingerprintFor(
            $campaign->business,
            (string) $campaign->standings_month,
            (int) $campaign->recipient_membership_id,
        );

        return view('owner.campaigns.recognition-edit', [
            'business' => $campaign->business,
            'campaign' => $campaign,
            'recipientName' => \App\Services\Campaigns\TopReferrerStandings::displayName($campaign->recipientMembership),
            'standingsChanged' => $fingerprint !== null
                && ($fingerprint['count'] !== (int) $campaign->standings_count || $fingerprint['form'] !== $campaign->standings_form),
            'currentCount' => $fingerprint['count'] ?? null,
        ]);
    }

    public function recognitionUpdate(Request $request, SmsCampaign $campaign, CampaignSender $sender): RedirectResponse
    {
        $campaign = $this->scoped($request, $campaign);

        if (! $campaign->isRecognition() || $campaign->status !== SmsCampaign::STATUS_DRAFT) {
            return redirect()->route('campaigns.show', $campaign);
        }

        $business = $campaign->business;
        $fingerprint = \App\Services\Campaigns\TopReferrerStandings::fingerprintFor(
            $business,
            (string) $campaign->standings_month,
            (int) $campaign->recipient_membership_id,
        );

        if ($request->boolean('use_updated')) {
            if ($fingerprint !== null) {
                $campaign->forceFill([
                    'body' => \App\Services\Campaigns\TopReferrerStandings::templateBody($business, $campaign->recipientMembership, (string) $campaign->standings_month, $fingerprint['count'], $fingerprint['form']),
                    'standings_count' => $fingerprint['count'],
                    'standings_form' => $fingerprint['form'],
                    'body_customized' => false,
                ])->save();
            }
        } else {
            $validated = $request->validate([
                'body' => ['required', 'string', new CampaignBodyLength],
            ]);

            $template = $fingerprint !== null
                ? \App\Services\Campaigns\TopReferrerStandings::templateBody($business, $campaign->recipientMembership, (string) $campaign->standings_month, $fingerprint['count'], $fingerprint['form'])
                : null;

            $campaign->forceFill([
                'body' => (string) $validated['body'],
                'body_customized' => $template === null || (string) $validated['body'] !== $template,
            ])->save();
        }

        $sender->stampFigures($campaign);

        return redirect()->route('campaigns.confirm', $campaign);
    }

    /**
     * Send something like this again (LV-01; CAMP-20): a sent
     * campaign's body becomes a fresh draft. Only the body
     * carries over — the audience re-derives from the
     * eligibility rule at compose, confirm, and send, never
     * from the original's recipient rows.
     *
     * ID-03 divergence (recorded in the design, section 7
     * note 2): a SEGMENT campaign's duplicate carries its
     * audience_segment with its body. Body and segment are a
     * pair whose premises match; a body-only copy of a
     * win-back would silently re-audience its text to people
     * it is false for.
     */
    public function duplicate(Request $request, SmsCampaign $campaign): RedirectResponse
    {
        $business = $request->user()->business;

        if (! $this->available()) {
            return redirect()->route('campaigns.index')
                ->with('status', 'Text campaigns are not available right now.');
        }

        // F-1: the campaign arrives by implicit model binding
        // (the sibling controllers' convention), so a word-shaped
        // or missing id is the designed 404 before this runs;
        // what remains here is the tenant + state check the
        // scoped query used to carry.
        abort_unless($campaign->business_id === $business->id && $campaign->status === SmsCampaign::STATUS_SENT, 404);
        $original = $campaign;

        // ID-02 (TR-11 guardrail 3): duplicate is suppressed
        // for recognition campaigns — duplicating would copy a
        // personalised body into a whole-audience draft, the
        // exact failure the recipient lock exists to prevent.
        if ($original->isRecognition()) {
            return redirect()->route('campaigns.show', $original)
                ->with('status', 'A top referrer thank-you goes to one customer, so it cannot be copied to a new campaign.');
        }

        $copy = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => $original->body,
            'audience_segment' => $original->audience_segment,
            'origin' => $original->audience_segment !== null ? 'winback_duplicate' : 'preset',
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
            'draft_id' => ['nullable', 'integer'],
        ]);

        // ID-03: for a segment draft, the estimate prices the
        // draft's stored segment (never a segment named by the
        // request); any other estimate prices the whole list.
        $segment = null;
        if (! empty($validated['draft_id'])) {
            $segment = SmsCampaign::query()
                ->where('business_id', $business->id)
                ->where('status', SmsCampaign::STATUS_DRAFT)
                ->find((int) $validated['draft_id'])?->audience_segment;
        }

        $figures = $sender->figures($business, (string) $validated['body'], $segment);

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

            // ID-02: the generic store never writes a
            // recognition draft's body either (same lock as
            // the composer open, on the write path).
            if ($campaign !== null && $campaign->isRecognition()) {
                return redirect()->route('campaigns.recognition.edit', $campaign);
            }
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

    public function confirm(Request $request, SmsCampaign $campaign, CampaignSender $sender): View|RedirectResponse
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

        // ID-02: a recognition draft's confirm is its own
        // recompute (design section 3.5): the standings are
        // re-read, an uncustomised body whose fingerprint moved
        // is regenerated and re-stamped before render, and a
        // customised body whose fingerprint moved renders with
        // the TR-09(b) line and no send form until the owner
        // re-confirms through the scoped editor.
        if ($campaign->isRecognition()) {
            return $this->recognitionConfirm($request, $campaign, $sender);
        }

        // The confirm render recomputes from ground truth and
        // stamps the draft, so the send's comparison is against
        // exactly what the owner approved here. A segment draft
        // recomputes its segment audience (ID-03 section 1.5).
        $sender->stampFigures($campaign);
        $figures = $sender->figures($business, $campaign->body, $campaign->audience_segment);
        $audience = CampaignAudience::forBusiness($business, $campaign->audience_segment, $campaign->winback_exempt_campaign_id);

        return view('owner.campaigns.confirm', [
            'business' => $business,
            'campaign' => $campaign->fresh(),
            'figures' => $figures,
            'audience' => $audience,
            'flowState' => $this->flowState($business),
            'segmentPriceCents' => (int) config('billing.sms_segment_price_cents'),
        ]);
    }

    /**
     * The recognition confirm render (ID-02): branches, in
     * order — stale month (TR-09c, no send form), standings
     * moved (regenerate + TR-09a when uncustomised; TR-09b and
     * no send form when customised), leader no longer a
     * sendable leader (the confirm's zero state; the F1-Z form
     * only when the LEADER'S OWN id is recency-skipped), flow
     * no longer active (no send form), else the TR-08/TR-11
     * scoped send form.
     */
    private function recognitionConfirm(Request $request, SmsCampaign $campaign, CampaignSender $sender): View|RedirectResponse
    {
        $business = $campaign->business;
        $month = (string) $campaign->standings_month;
        $recipientId = (int) $campaign->recipient_membership_id;
        $stale = \App\Services\Campaigns\TopReferrerStandings::currentStandingsMonth($business) !== $month;
        $notice = null;

        if (! $stale) {
            $fingerprint = \App\Services\Campaigns\TopReferrerStandings::fingerprintFor($business, $month, $recipientId);
            $moved = $fingerprint !== null
                && ($fingerprint['count'] !== (int) $campaign->standings_count || $fingerprint['form'] !== $campaign->standings_form);

            if ($moved && ! $campaign->body_customized) {
                $campaign->forceFill([
                    'body' => \App\Services\Campaigns\TopReferrerStandings::templateBody($business, $campaign->recipientMembership, $month, $fingerprint['count'], $fingerprint['form']),
                    'standings_count' => $fingerprint['count'],
                    'standings_form' => $fingerprint['form'],
                ])->save();
                $notice = 'changed_regenerated';
            } elseif ($moved && $campaign->body_customized) {
                $notice = 'changed_customized';
            } elseif ($fingerprint === null) {
                $notice = 'ineligible';
            }

        }

        $sender->stampFigures($campaign);
        $figures = $sender->recognitionFigures($campaign);
        $audience = CampaignAudience::forBusiness($business);
        $nextDraft = SmsCampaign::query()
            ->where('business_id', $business->id)
            ->where('provenance', SmsCampaign::PROVENANCE_TOP_REFERRER)
            ->where('standings_month', $month)
            ->where('status', SmsCampaign::STATUS_DRAFT)
            ->where('id', '!=', $campaign->id)
            ->orderBy('id')
            ->first();

        return view('owner.campaigns.confirm', [
            'business' => $business,
            'campaign' => $campaign->fresh(),
            'figures' => $figures,
            'audience' => $audience,
            'flowState' => $this->flowState($business),
            'segmentPriceCents' => (int) config('billing.sms_segment_price_cents'),
            'recognition' => [
                'stale' => $stale,
                'notice' => $notice,
                'monthName' => \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->format('F'),
                'recipientName' => $campaign->recipientMembership !== null
                    ? \App\Services\Campaigns\TopReferrerStandings::displayName($campaign->recipientMembership)
                    : 'This customer',
                'currentCount' => \App\Services\Campaigns\TopReferrerStandings::countsForMonth($business, $month)[$recipientId] ?? null,
                // Two-key Condition 2: the F1-Z branch is
                // leader-scoped — it keys on the LEADER'S OWN
                // recency skip and shows the leader's own
                // earliest-return date, never business-wide
                // exclusion totals or another customer's date.
                'leaderRecencySkipped' => in_array($recipientId, array_merge(
                    $audience['recency_skipped_ids']['spacing'] ?? [],
                    $audience['recency_skipped_ids']['cap'] ?? [],
                ), true),
                'leaderReturnDate' => isset($audience['recency_return_by_id'][$recipientId])
                    ? $business->localTime($audience['recency_return_by_id'][$recipientId])->format('M j, Y')
                    : null,
                'nextDraftId' => $nextDraft?->id,
                'nextDraftName' => $nextDraft?->recipientMembership !== null
                    ? \App\Services\Campaigns\TopReferrerStandings::displayName($nextDraft->recipientMembership)
                    : null,
            ],
        ]);
    }

    public function send(Request $request, SmsCampaign $campaign, CampaignSender $sender): RedirectResponse
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
            // F2 (Amendment 45): a closed business's program
            // has ended; the send is refused, the draft stays.
            'blocked_closed' => redirect()->route('campaigns.confirm', $campaign)
                ->with('status', 'Your program has ended, so text campaigns cannot be sent.'),
            // ID-03 (Amendment 46): a segment draft's flow
            // re-check refused the send; the draft stays saved
            // and the flow state's own surfaces explain why.
            'blocked_flow' => redirect()->route('campaigns.confirm', $campaign)
                ->with('status', 'Your program is not active right now, so this text cannot be sent.'),
            // ID-02 (TR-09): the recognition send outcomes.
            'recognition_changed' => redirect()->route('campaigns.confirm', $campaign)
                ->with('status', 'The standings changed. Check the updated count, then send again.'),
            'recognition_ineligible' => redirect()->route('campaigns.confirm', $campaign)
                ->with('status', 'This customer cannot receive texts right now, so the thank-you was not sent.'),
            'recognition_recent' => redirect()->route('campaigns.confirm', $campaign)
                ->with('status', 'This customer got a campaign text too recently, so the thank-you was not sent.'),
            'recognition_stale' => redirect()->route('campaigns.confirm', $campaign)
                ->with('status', 'That thank-you month has closed. It was not sent.'),
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

    public function show(Request $request, SmsCampaign $campaign): View
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

        // ID-02 (TR-10): a recognition campaign's detail reads
        // the REDACTED evidence copy once a recipient row
        // exists — never a re-rendered live token. Before any
        // send, the body alone renders (it holds no token: the
        // link is a render-time appendage).
        $renderedBody = MessageCopy::campaign($campaign->business, $campaign->body);
        if ($campaign->isRecognition()) {
            $evidence = $campaign->recipients()->whereNotNull('sms_message_id')->with('smsMessage')->first()?->smsMessage;
            $renderedBody = $evidence !== null
                ? $evidence->rendered_body
                : $campaign->body;
        }

        return view('owner.campaigns.show', [
            'business' => $request->user()->business,
            'campaign' => $campaign,
            'renderedBody' => $renderedBody,
            'counts' => $counts,
            'total' => $total,
            'processed' => $processed,
            'recipients' => $recipients,
            // RK-02: the closed-loop results block renders for a
            // completed campaign (CAMP-22/23); every other state
            // keeps the counts + money blocks it already had.
            'results' => $campaign->status === SmsCampaign::STATUS_SENT
                ? CampaignResults::forCampaign($campaign)
                : null,
            'mask' => fn (string $phone) => PhoneNumber::mask($phone),
            'failureReason' => fn (?string $code) => self::FAILURE_REASONS[$code ?? '']
                ?? 'The message could not be delivered.',
        ]);
    }

    /**
     * RK-02 (CAMP-23): resend a sent campaign's text to the
     * failed recipients who are still eligible. The resend is a
     * NEW campaign carrying the same body; its audience is the
     * source's failed recipients intersected with the current
     * CampaignAudience eligibility, recomputed again inside
     * CampaignSender::sendToMembers at send time (never the
     * stored recipient list). Landing reuses GEN-04's outcome
     * toast via sentRedirect().
     */
    public function resend(Request $request, SmsCampaign $campaign, CampaignSender $sender): RedirectResponse
    {
        $business = $request->user()->business;

        if (! $this->available()) {
            return redirect()->route('campaigns.index')
                ->with('status', 'Text campaigns are not available right now.');
        }

        // F-1: model binding resolves existence (see duplicate);
        // the tenant + state check is what remains.
        abort_unless($campaign->business_id === $business->id && $campaign->status === SmsCampaign::STATUS_SENT, 404);
        $source = $campaign;

        $results = CampaignResults::forCampaign($source);

        if ($results['resend_ids'] === []) {
            return redirect()->route('campaigns.show', $source)
                ->with('status', 'None of the failed numbers can receive texts anymore.');
        }

        $copy = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => $source->body,
            // ID-03: a segment source's resend stays a segment
            // campaign, with the source exempted from the
            // win-back cooldown (its own failed recipients).
            'audience_segment' => $source->audience_segment,
            // ID-02: a recognition source's resend carries its
            // provenance and recipient lock (CAMP-23 is the one
            // path that may re-send the SAME approved text
            // after the window; it does not re-decide
            // recognition).
            'provenance' => $source->provenance,
            'recipient_membership_id' => $source->recipient_membership_id,
            'standings_month' => $source->standings_month,
            'standings_count' => $source->standings_count,
            'standings_form' => $source->standings_form,
            'body_customized' => $source->body_customized,
            'origin' => $source->audience_segment !== null ? 'winback_resend' : 'preset',
            'winback_exempt_campaign_id' => $source->audience_segment !== null ? $source->id : null,
            'status' => SmsCampaign::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::write('campaign.resent', $request->user(), ['source_campaign_id' => $source->id], $business);

        $result = $sender->sendToMembers($copy, $results['resend_ids']);

        if ($result['outcome'] === 'paused' || $result['outcome'] === 'blocked_closed' || $result['outcome'] === 'blocked_flow') {
            // Nothing was queued; the copy must not linger as a
            // phantom draft of a send that never happened.
            $copy->delete();

            return redirect()->route('campaigns.show', $source)
                ->with('status', match ($result['outcome']) {
                    'blocked_closed' => 'Your program has ended, so text campaigns cannot be sent.',
                    'blocked_flow' => 'Your program is not active right now, so this text cannot be sent.',
                    default => 'Text campaigns are paused right now. Please try again tomorrow.',
                });
        }

        return $this->sentRedirect($request, $copy);
    }

    public function status(Request $request, SmsCampaign $campaign): JsonResponse
    {
        $campaign = $this->scoped($request, $campaign);
        $counts = $this->stateCounts($campaign);

        return response()->json([
            'status' => $campaign->status,
            'total' => (int) array_sum($counts),
            'counts' => $counts,
        ]);
    }

    public function destroy(Request $request, SmsCampaign $campaign): RedirectResponse
    {
        // F-1: model binding resolves existence (see duplicate);
        // the tenant + state check is what remains.
        abort_unless($campaign->business_id === $request->user()->business->id && $campaign->status === SmsCampaign::STATUS_DRAFT, 404);

        $campaign->delete();

        return redirect()->route('campaigns.index')
            ->with('status', 'Draft discarded.');
    }

    /**
     * Another business's campaign is a 404, the same answer a
     * stranger gets (the statement-page convention). Existence
     * itself is resolved by implicit model binding before the
     * controller runs (F-1: a word-shaped or missing id never
     * reaches here, where the old int type-hint threw a
     * TypeError and a 500); this check carries the tenant leg.
     */
    private function scoped(Request $request, SmsCampaign $campaign): SmsCampaign
    {
        abort_unless($campaign->business_id === $request->user()->business->id, 404);

        return $campaign;
    }

    /**
     * F2 (Amendment 45): the campaign flow-state truth, in the
     * forms the compose/confirm surfaces branch on. A closed
     * business is blocked outright; any other inactive flow
     * carries the named warning but may still send. The
     * inactive forms split by cause (two-key Condition 1,
     * 2026-10-28) because the warning's redemption clause is
     * not true in every inactive state: 'textless' (no friend
     * reward text) keeps the strong form, while 'inactive'
     * with the text set (past-due paused, no card yet, platform
     * disabled) renders the scoped form — new arrivals are
     * stopped at the flow gate in both, but only the textless
     * cause can also stop a redemption (the missing-text
     * backstop); the claim and redemption services carry no
     * pause, billing, or status gate, so rewards already
     * earned still redeem in every text-set inactive state.
     */
    private function flowState(Business $business): string
    {
        if ($business->isClosed()) {
            return 'closed';
        }

        if ($business->flowIsActive()) {
            return 'active';
        }

        if (! $business->hasFriendRewardText()) {
            return 'textless';
        }

        return 'inactive';
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
