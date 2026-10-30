{{--
    Weekly top referrers (spec Section 5.1), included by the
    Overview in both section orders. OV-6 (post-r26): the R1
    row grammar replaces the stacked table, so no pill clips
    and no date wraps at phone widths: monogram, name, a quiet
    "Registered {date}" meta line, and the referrals count with
    the Active / Link off pill at the right. Row content is the
    table's, unchanged.
--}}
<div class="card mt-4">
    <h2 class="type-title-3 text-text-1">Weekly top referrers</h2>
    @if ($topReferrers->isNotEmpty())
        <ul class="mt-3 divide-y divide-line">
            @foreach ($topReferrers as $membership)
                <li class="flex items-center gap-3 py-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-surface-2 text-text-1" aria-hidden="true"><span style="font-size: 13px; font-weight: 650">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($membership->customer->first_name, 0, 1)) }}{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($membership->customer->last_name, 0, 1)) }}</span></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-text-1">{{ $membership->customer->fullName() }}</span>
                        <span class="block text-sm text-text-2">Registered {{ $business->localTime($membership->created_at)->format('M j, Y') }}</span>
                    </span>
                    <span class="flex flex-none items-center gap-2">
                        <span class="font-semibold text-text-1">{{ $membership->weekly_referrals }}</span>
                        <span class="pill {{ $membership->share_token_state === 'active' ? 'pill-success' : 'pill-neutral' }}">{{ $membership->share_token_state === 'active' ? 'Active' : 'Link off' }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="type-body-sm mt-1 text-text-2">No referrals yet. Share your QR code to get started.</p>
    @endif
</div>
