{{--
    The cumulative chart card (design Section 6.4 item 6),
    included by the Overview in the standing position (before
    the counters, OV-3) and the legacy position (after them).
    OV-1 (post-r26): the line carries its values: y-axis tick
    labels at 0, the maximum, and the mid value when the
    maximum is 4 or more, quiet gridlines in the line token, a
    bold end-of-line value label, and the maximum named in the
    SVG aria-label. The "View as table" disclosure stays the
    full data alternative.
--}}
<div class="card mt-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="type-title-3 text-text-1">Cumulative referred customers</h2>
        <p class="flex gap-1">
            <a href="{{ route('dashboard', ['range' => '7']) }}" @if ($chartRange === '7') aria-current="true" class="btn btn-ghost btn-sm border border-line-strong bg-surface-3" @else class="btn btn-ghost btn-sm" @endif>7 days</a>
            <a href="{{ route('dashboard', ['range' => '30']) }}" @if ($chartRange === '30') aria-current="true" class="btn btn-ghost btn-sm border border-line-strong bg-surface-3" @else class="btn btn-ghost btn-sm" @endif>30 days</a>
            <a href="{{ route('dashboard', ['range' => 'all']) }}" @if ($chartRange === 'all') aria-current="true" class="btn btn-ghost btn-sm border border-line-strong bg-surface-3" @else class="btn btn-ghost btn-sm" @endif>All time</a>
        </p>
    </div>
    @if ($chartTotal > 0)
        @php
            $maxTotal = max(1, collect($chart)->max('total'));
            $points = collect($chart)->map(function ($point, $index) use ($chart, $maxTotal) {
                $x = count($chart) > 1 ? 40 + ($index * (672 / (count($chart) - 1))) : 376;
                $y = 168 - (($point['total'] / $maxTotal) * 152);

                return round($x, 1).','.round($y, 1);
            })->implode(' ');
            $yFor = fn ($value) => round(168 - (($value / $maxTotal) * 152), 1);
            $midTotal = $maxTotal / 2;
            $showMid = $maxTotal >= 4;
            $midLabel = $showMid ? (floor($midTotal) === $midTotal ? (string) (int) $midTotal : number_format($midTotal, 1)) : null;
            $lastPoint = $chart[count($chart) - 1];
            $endLabelY = max(15, $yFor($lastPoint['total']) - 8);
        @endphp
        <svg viewBox="0 0 720 190" class="mt-3 w-full" role="img" aria-label="Cumulative referred customers: {{ $chartTotal }} total, maximum {{ $maxTotal }}">
            <line data-chart-gridline x1="40" x2="712" y1="{{ $yFor($maxTotal) }}" y2="{{ $yFor($maxTotal) }}" stroke="var(--color-line)" stroke-width="1" />
            @if ($showMid)
                <line data-chart-gridline x1="40" x2="712" y1="{{ $yFor($midTotal) }}" y2="{{ $yFor($midTotal) }}" stroke="var(--color-line)" stroke-width="1" />
            @endif
            <line data-chart-gridline x1="40" x2="712" y1="{{ $yFor(0) }}" y2="{{ $yFor(0) }}" stroke="var(--color-line)" stroke-width="1" />
            <text data-chart-tick="max" x="32" y="{{ $yFor($maxTotal) + 4 }}" text-anchor="end" font-size="12" fill="var(--color-text-2)">{{ $maxTotal }}</text>
            @if ($showMid)
                <text data-chart-tick="mid" x="32" y="{{ $yFor($midTotal) + 4 }}" text-anchor="end" font-size="12" fill="var(--color-text-2)">{{ $midLabel }}</text>
            @endif
            <text data-chart-tick="0" x="32" y="{{ $yFor(0) + 4 }}" text-anchor="end" font-size="12" fill="var(--color-text-2)">0</text>
            <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="2.5" class="text-data-amber" />
            <text data-chart-end-value x="712" y="{{ $endLabelY }}" text-anchor="end" font-size="14" font-weight="700" fill="var(--color-text-1)">{{ $lastPoint['total'] }}</text>
        </svg>
        <p class="flex justify-between text-sm text-text-2">
            <span>{{ $chart[0]['label'] }}</span>
            <span>Customers</span>
            <span>{{ $chart[count($chart) - 1]['label'] }}</span>
        </p>
        <details class="mt-2">
            <summary class="flex min-h-12 cursor-pointer items-center font-semibold text-link">View as table</summary>
            <div class="mt-3 overflow-x-auto"><table class="tbl">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col">Date</th>
                        <th scope="col" class="num">Customers</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($chart as $point)
                        <tr>
                            <td>{{ $point['label'] }}</td>
                            <td class="num">{{ $point['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </details>
    @else
        <p class="type-body-sm mt-1 text-text-2">Your first visits will draw this line.</p>
    @endif
</div>
