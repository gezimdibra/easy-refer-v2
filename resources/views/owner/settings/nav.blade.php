{{-- Settings section navigation (spec Section 5.5, SET-01):
     Settings is an index of separate pages, one job each; this
     sub-navigation is how the owner moves between them. The
     Security page keeps its established URL and joins here. --}}
<nav aria-label="Settings" class="mb-5">
    <ul class="flex flex-wrap gap-x-4 gap-y-2 text-base">
        @foreach ([
            'settings.profile' => 'Business profile',
            'settings.rewards' => 'Rewards',
            'settings.value' => 'Value',
            'settings.reviews' => 'Reviews',
            'settings.notifications' => 'Notifications',
            'settings.limits' => 'Referral limits',
            'security' => 'Security',
            'settings.account' => 'Account',
        ] as $routeName => $label)
            <li>
                <a href="{{ route($routeName) }}"
                    @if (request()->routeIs($routeName)) aria-current="page" class="font-semibold text-brand-700 underline" @else class="font-semibold text-brand-700 hover:underline" @endif>{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>
