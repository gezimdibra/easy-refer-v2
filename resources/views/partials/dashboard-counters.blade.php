{{--
    The standing counters (spec Section 5.1), included by the
    Overview in both section orders. OV-4 (post-r26): the grid
    carries its "All time" heading exactly once, and the New
    customers tile names its own window in its caption. OV-3:
    in the standing state the tile order leads with the totals
    the owner checks first (completed, total customers, new
    customers, pending, then rewards and messages); the legacy
    order is unchanged for the setup states.
--}}
@php
    $counterTiles = [
        ['label' => 'Referrals completed', 'value' => $counters['completed_total']],
        ['label' => 'Pending referrals', 'value' => $counters['pending_total']],
        ['label' => 'New customers', 'value' => $counters['new_customers'], 'caption' => 'In the last 5 days'],
        ['label' => 'Total customers', 'value' => $counters['total_customers']],
        ['label' => 'Rewards issued', 'value' => $counters['rewards_issued']],
        ['label' => 'Rewards claimed', 'value' => $counters['rewards_claimed']],
        ['label' => 'Promotional messages sent', 'value' => $counters['promo_sent'], 'span' => 'col-span-2 sm:col-span-3 lg:col-span-2'],
    ];
    if ($isStanding ?? false) {
        $counterTiles = [$counterTiles[0], $counterTiles[3], $counterTiles[2], $counterTiles[1], $counterTiles[4], $counterTiles[5], $counterTiles[6]];
    }
@endphp
<h2 class="type-title-3 mt-6 text-text-1" data-ov-counters-heading>All time</h2>
<div class="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line shadow-e1 sm:grid-cols-3 lg:grid-cols-4">
    @foreach ($counterTiles as $counterTile)
        <div class="tile {{ $counterTile['span'] ?? '' }} bg-surface-0 p-5">
            <p class="tile-label">{{ $counterTile['label'] }}</p>
            <p class="tile-value">{{ $counterTile['value'] }}</p>
            @isset($counterTile['caption'])
                <p class="tile-delta">{{ $counterTile['caption'] }}</p>
            @endisset
        </div>
    @endforeach
</div>
