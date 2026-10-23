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
--}}
@php($plan = $plan ?? \App\Models\Plan::launch())
<div class="card mx-auto mt-10 max-w-[720px]"
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
            <label class="form-label" for="calc-share">Share of customers who bring a friend who visits</label>
            <p class="form-input flex items-center gap-2">1 in <input class="w-16 border-0 bg-transparent p-0 focus:outline-none" id="calc-share" type="number" min="1" max="1000" x-model.number="oneIn" aria-label="Share of customers who bring a friend who visits, as one in a number"></p>
        </div>
    </div>

    <div class="mt-6 space-y-1 text-lg text-text-1" aria-live="polite">
        <p>About <strong x-text="customers">22</strong> new customers a month</p>
        <p>Your Easy Refer fee: about $<strong x-text="fee">44</strong> a month</p>
        <p>Worth about $<strong x-text="value">880</strong> in first-visit sales</p>
    </div>
    <p class="form-help mt-3">Estimates from your own numbers, not our results.</p>

    <a class="btn btn-secondary mt-5" :href="registerUrl + (registerUrl.includes('?') ? '&' : '?') + 'ticket=' + ticket">Start free</a>
</div>
