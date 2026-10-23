@extends('layouts.app')

@section('title', 'Notifications')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="text-[28px] font-bold">Notifications</h1>

    @include('owner.settings.nav')

    <form method="POST" action="{{ route('settings.notifications.update') }}" class="card">
        @csrf

        <div class="space-y-5">
            <div>
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="owner_text_notifications" value="1" @checked(old('owner_text_notifications', $business->owner_text_notifications)) class="mt-1">
                    <span>
                        <span class="font-semibold">Text me when a referred friend visits</span>
                        <span class="block text-text-2">Sends one text to your contact phone for your first referral visit. It sends only once.</span>
                    </span>
                </label>
            </div>

            <div>
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="weekly_summary" value="1" @checked(old('weekly_summary', ! $business->weekly_digest_opt_out)) class="mt-1">
                    <span>
                        <span class="font-semibold">Weekly summary email</span>
                        <span class="block text-text-2">One email a week with your visits, rewards, and fees. Turn it off to stop the summary. Billing emails always send.</span>
                    </span>
                </label>
            </div>

            <div>
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="nudges" value="1" @checked(old('nudges', ! $business->nudge_opt_out)) class="mt-1">
                    <span>
                        <span class="font-semibold">Referral reminder nudges</span>
                        <span class="block text-text-2">Occasional emails suggesting a next step for your program, like adding your review link.</span>
                    </span>
                </label>
            </div>
        </div>

        <p class="mt-5"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
@endsection
