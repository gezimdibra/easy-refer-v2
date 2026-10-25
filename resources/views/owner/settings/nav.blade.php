{{-- Settings section navigation (spec Section 5.5, SET-01;
     redesign-spec.md Section 10.5 item 5): a left subnav in
     rail-item styling, one job per page. The Security page keeps
     its established URL and joins here. --}}
<nav aria-label="Settings">
    <ul class="flex gap-1 overflow-x-auto pb-2 lg:flex-col lg:overflow-visible lg:pb-0">
        @foreach ([
            'settings.profile' => 'Business profile',
            'settings.rewards' => 'Rewards',
            'settings.value' => 'Value',
            'settings.reviews' => 'Reviews',
            'settings.notifications' => 'Notifications',
            'settings.limits' => 'Referral limits',
            'security' => 'Security',
            'settings.billing' => 'Billing',
            'settings.account' => 'Account',
        ] as $routeName => $label)
            <li class="flex-none lg:flex-auto">
                <a href="{{ route($routeName) }}"
                    @if (request()->routeIs($routeName)) aria-current="page" class="flex min-h-[48px] items-center rounded-md border border-line-strong bg-brand-50 px-3 text-[15px] font-semibold whitespace-nowrap text-link" @else class="flex min-h-[48px] items-center rounded-md px-3 text-[15px] font-medium whitespace-nowrap text-text-2 hover:bg-surface-1 hover:text-text-1" @endif>{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>
