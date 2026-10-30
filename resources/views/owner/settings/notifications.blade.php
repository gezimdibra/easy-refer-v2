@extends('layouts.app')

@section('title', 'Notifications')


@section('content')
    <h1 class="type-title-2 text-text-1">Notifications</h1>

    <p class="type-body mt-2 max-w-[68ch] text-text-2">The texts and emails Easy Refer sends you about your program.</p>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    {{-- SN-1 v2 (Amendment 38, Train A3-1): the summary is
         composed from the ONE real switch (the same old() /
         business expression the switch renders), so the summary
         and the switch cannot disagree. The weekly-digest and
         reminder-nudge toggles were removed: no sender exists
         for either (their stored opt-out columns stay inert).
         The summary names what the text switch controls, then
         the always-on truth: the first-visit email, billing
         emails, and security emails send in every state. --}}
    @php($textOn = (bool) old('owner_text_notifications', $business->owner_text_notifications))
    @php($summaryLine = $textOn
        ? 'Right now: you get a text and an email when your first referred friend visits. Billing emails and security emails about your account always send.'
        : 'Right now: no texts about your program. You still get an email when your first referred friend visits. Billing emails and security emails about your account always send.')

    <form method="POST" action="{{ route('settings.notifications.update') }}" class="panel [&_input]:max-w-[560px] [&_select]:max-w-[560px] [&_textarea]:max-w-[560px]" data-loading>
        @csrf

        <p class="type-body text-text-1" data-notifications-summary>{{ $summaryLine }}</p>

        <h2 class="type-title-3 mt-6 text-text-1">About your program</h2>

        <div class="mt-4 space-y-5">
            <div>
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="owner_text_notifications" value="1" @checked(old('owner_text_notifications', $business->owner_text_notifications)) class="switch mt-1">
                    <span>
                        <span class="font-semibold">Text me when a referred friend visits</span>
                        <span class="block text-text-2">Sends one text to your contact phone for your first referral visit. It sends only once.</span>
                    </span>
                </label>
            </div>

        </div>

        <h2 class="type-title-3 mt-6 text-text-1">Email</h2>

        <div class="mt-4 space-y-5">
            {{-- SN-2: the always-on billing row. The switch is
                 disabled, checked, and carries NO name attribute,
                 so it can never be submitted or alter billing mail. --}}
            <div>
                <div class="flex items-start gap-3">
                    <input type="checkbox" checked disabled class="switch mt-1" aria-disabled="true">
                    <span>
                        <span class="font-semibold">Billing emails</span>
                        <span class="block text-text-2">Always on. Receipts, payment problems, and charge notices always send.</span>
                    </span>
                </div>
            </div>

            {{-- M-5 (Amendment 72, [M5-D1-F]): the setup-reminder
                 turn-off ships with its sender. It governs the
                 setup drip only (D1 to D3); payment and security
                 emails are never turned off, as the drip footer
                 itself states. The column stores the negative:
                 checked here means reminders are ON. --}}
            <div>
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="setup_reminders" value="1" @checked(old('setup_reminders', ! $business->setup_reminders_opt_out)) class="switch mt-1">
                    <span>
                        <span class="font-semibold">Setup reminder emails</span>
                        <span class="block text-text-2">Occasional emails about a setup step that is not finished yet. Payment and security emails are never turned off.</span>
                    </span>
                </label>
            </div>
        </div>

        <p class="mt-5"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
        </div>
    </div>
@endsection
