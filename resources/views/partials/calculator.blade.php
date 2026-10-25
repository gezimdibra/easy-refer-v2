{{--
    The value calculator (spec Section 2.6, GRW-10; inventory
    LAND-CALC-01). Client-side only: inputs are never stored before
    account creation. The fee arithmetic implements the Section 9.5
    cap rule exactly: visits are billed in order until the next
    charge would cross the cap, so the fee tops out at the largest
    whole number of visits the cap admits (49 at the launch price),
    and the one-time free visits are not subtracted from a monthly
    estimate. Plan values arrive as server-rendered bootstrap data;
    no URL string is built in JavaScript beyond appending the
    visitor's own ticket input to the server-rendered signup URL.
    Redesign (redesign-spec.md Section 10.1 item 8): the worksheet
    becomes a surface-0 panel at radius-xl with 32px padding; the
    result sentences keep their inventory wording, with the figures
    set in the stat-sm step.
--}}
@php($plan = $plan ?? \App\Models\Plan::launch())
{{-- R10 (release 08b): the calculator gets its heading and intro
     (LAND-CALC-01 gains both) so the block no longer opens cold
     with a counter question. The amounts render from the plan
     table like every other price. On the home page the partial
     sits inside its own section (08b item 2.2); the heading is
     the section's H2 there and reads as the block heading inside
     the verticals' pricing section. --}}
<div class="mx-auto mt-16 max-w-[720px] text-center">
    <h2 class="type-title-1 text-text-1">See what referrals could bring in</h2>
    {{-- Copy release (Amendment 11, O-2): the intro is the
         slim-down short form; PROSE-GAP floor lifts it to mt-4. --}}
    <p class="type-body mt-4 text-text-2">Use your own numbers: your weekly customers and your average sale. The math uses the same {{ \App\Support\Money::usd($plan->per_referral_cents) }} fee and the same {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} limit.</p>
</div>
<div class="mx-auto mt-10 max-w-[720px] rounded-xl border border-line bg-surface-0 shadow-e1" style="padding: 32px"
    x-data="{
        weekly: 100,
        ticket: 40,
        oneIn: 20,
        perVisit: {{ $plan->per_referral_cents }},
        cap: {{ $plan->monthly_cap_cents }},
        registerUrl: '{{ $registerUrl ?? route('register') }}',
        get monthly() { return Math.round(this.weekly * 52 / 12) },
        get customers() { return Math.max(0, Math.round(this.monthly / Math.max(1, this.oneIn))) },
        get maxBillable() { return Math.floor(this.cap / this.perVisit) },
        get fee() { return Math.min(this.customers, this.maxBillable) * this.perVisit / 100 },
        get value() { return this.customers * this.ticket },
    }">
    <div class="grid gap-5 sm:grid-cols-3">
        <div>
            <label class="form-label" for="calc-weekly">How many people pay at your counter each week?</label>
            <input class="form-input" id="calc-weekly" type="number" min="0" max="100000" x-model.number="weekly">
        </div>
        <div>
            <label class="form-label" for="calc-ticket">Your average ticket</label>
            <input class="form-input" id="calc-ticket" type="number" min="0" max="100000" x-model.number="ticket">
        </div>
        <div>
            <label class="form-label" for="calc-share">Customers who bring a friend</label>
            {{-- F5 (release 08b): the inline share input takes the
                 48px target height (h-12); the font stays 16px and
                 the "1 in [ ]" sentence layout is unchanged. --}}
            <p class="form-input flex items-center gap-2">1 in <input class="h-12 w-16 border-0 bg-transparent p-0 focus:outline-none" id="calc-share" type="number" min="1" max="1000" x-model.number="oneIn" aria-label="Customers who bring a friend, as one in a number"></p>
        </div>
    </div>

    <div class="mt-6 space-y-2" aria-live="polite">
        <p class="type-body-lg text-text-1">About <strong class="type-stat-sm" x-text="customers">22</strong> new customers a month</p>
        <p class="type-body-lg text-text-1">Your Easy Refer fee: about $<strong class="type-stat-sm" x-text="fee">44</strong> a month</p>
        <p class="type-body-lg text-text-1">Worth about $<strong class="type-stat-sm" x-text="value">880</strong> in first-visit sales</p>
    </div>
    <p class="form-help mt-4">Estimates from your own numbers, not our results.</p>
    {{-- Amendment 12 (release 11, LAND-CALC-01): the honesty
         notes sit with the estimate label, glued to the results:
         the sales figure is gross of the rewards the business
         hands out, and the monthly fee estimate does not
         subtract the one-time free visits. The free count
         renders from config. --}}
    <p class="form-help mt-2">The sales figure is before the rewards you hand out. Our fee, shown above, is the only charge from us.</p>
    <p class="form-help mt-2">Your first {{ (int) config('billing.free_visits_at_signup') }} visits are free. This monthly estimate does not subtract them.</p>

    {{-- C-02: this anchor IS the primary signup action, so it carries
         the primary treatment; the label never rides an outline or
         secondary button. --}}
    <a class="btn btn-primary mt-5" :href="registerUrl + (registerUrl.includes('?') ? '&' : '?') + 'ticket=' + ticket">Start free</a>
</div>
