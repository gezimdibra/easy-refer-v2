@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    {{-- The settings index is the directory (ST-1, ST-5): grouped
         entries with icons, one-line descriptions, and chevrons,
         in the same panel system as the rest of the owner app.
         The subpages keep the left subnav for moving between
         sections once inside; the index itself does not repeat
         it as a bare text list. --}}
    <h1 class="type-title-2 text-text-1">Settings</h1>
    <p class="type-body mt-2 max-w-[68ch] text-text-2">Everything you can change about your program and your account.</p>

    <div class="mt-6 grid items-start gap-4 lg:grid-cols-2">
        @foreach ([
            [
                'heading' => 'Your program',
                'entries' => [
                    ['settings.rewards', 'gift', 'Rewards', 'What the friend and the person who referred them each get.'],
                    ['settings.value', 'wallet', 'Value', 'Your typical spend per visit, used for the sales estimates on your Overview.'],
                    ['settings.reviews', 'star', 'Reviews', 'Your Google review link, shown to customers after they visit.'],
                    ['settings.limits', 'users', 'Referral limits', 'How many rewards one customer can earn per day and per month.'],
                    ['settings.notifications', 'message-circle', 'Notifications', 'The texts and emails Easy Refer sends you about your program.'],
                ],
            ],
            [
                'heading' => 'Business and account',
                'entries' => [
                    ['settings.profile', 'layout-dashboard', 'Business profile', 'Your business name, type, and contact details.'],
                    ['security', 'lock', 'Security', 'Your password and two-factor authentication.'],
                    ['settings.billing', 'wallet', 'Billing', 'Your plan, your card on file, and your charges.'],
                    ['settings.account', 'settings', 'Account', 'End your referral program, settle your final bill, and see what happens to rewards.'],
                ],
            ],
        ] as $group)
            <div class="panel">
                <h2 class="type-title-3 text-text-1">{{ $group['heading'] }}</h2>
                <ul class="mt-2 divide-y divide-line">
                    @foreach ($group['entries'] as [$routeName, $icon, $label, $description])
                        <li>
                            <a href="{{ route($routeName) }}" class="flex min-h-[48px] flex-wrap items-center gap-3 py-3">
                                <span class="flex h-10 w-10 flex-none items-center justify-center rounded-lg bg-surface-2 text-text-1" aria-hidden="true"><x-icon :name="$icon" :size="20" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold text-text-1">{{ $label }}</span>
                                    <span class="type-body-sm block text-text-2">{{ $description }}</span>
                                </span>
                                {{-- SI-1/SI-2: the row's current state,
                                     computed once in the controller from
                                     the same predicates the row's own
                                     page uses. State rides inside the
                                     row link; it is never a control.
                                     Below lg the state wraps to its
                                     own line under the text (indented
                                     past the icon) instead of crushing
                                     the label column (T2R-01 row
                                     geometry rule); the chevron stays
                                     on the first line. --}}
                                @php($rowState = $indexStates[$routeName] ?? null)
                                @if ($rowState !== null)
                                    <span class="flex-none max-lg:order-4 max-lg:w-full max-lg:pl-[52px]">
                                        @if ($rowState['kind'] === 'pill')
                                            <span class="pill {{ $rowState['tone'] }}">{{ $rowState['text'] }}</span>
                                        @elseif ($rowState['kind'] === 'truncated')
                                            <span class="type-body-sm block max-w-[220px] truncate text-text-2 lg:max-w-[160px]">{{ $rowState['text'] }}</span>
                                        @else
                                            <span class="type-body-sm whitespace-nowrap text-text-2">{{ $rowState['text'] }}</span>
                                        @endif
                                    </span>
                                @endif
                                <x-icon name="chevron-right" :size="20" class="flex-none text-text-3 max-lg:order-3" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    <div class="panel mt-4">
        <h2 class="type-title-3 text-text-1">Setup checklist</h2>
        @if ($checklistDismissed)
            <p class="mt-1 text-text-2">Checklist is hidden. Show it again any time.</p>
            <form method="POST" action="{{ route('settings.checklist.reset') }}" class="mt-3" data-loading>
                @csrf
                <button type="submit" class="btn btn-secondary">Show the setup checklist again</button>
            </form>
        @else
            <p class="mt-1 text-text-2">Checklist is showing on your Overview.</p>
            <p class="mt-3"><a class="btn btn-secondary" href="{{ route('dashboard') }}">Open the checklist</a></p>
        @endif
    </div>
@endsection
