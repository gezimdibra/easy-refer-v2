<?php

namespace App\Support;

use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\SmsCampaignRecipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A billing record's window as the owner lived it (Train 3.5;
 * retro 2026-10-25, audit Section 4 seam 4). The stored
 * period_start / period_end are the UTC dates of the first and
 * last rows the record claimed; the window itself is a record
 * window, not a calendar month, and after a carry-forward it
 * covers parts of two calendar months. This helper names the
 * same window in business-local terms: the local readings
 * (Business::localTime) of the claimed rows' own instants, so
 * an edge event renders on the date the owner experienced it.
 * The window, its rows, and its accounting never move; only
 * the naming converts. A record with no claimed rows falls
 * back to its stored period dates, unnamed-converted, because
 * no instant survives to convert.
 */
final class StatementWindow
{
    /**
     * @return array{start: Carbon, end: Carbon, spans_two_months: bool, carry_line: ?string}
     */
    public static function forRecord(BillingRecord $record, Business $business): array
    {
        $windows = self::forRecords(collect([$record]), $business);

        return $windows[$record->id];
    }

    /**
     * Windows for many records in two aggregate queries, keyed
     * by record id (the billing history renders one per row).
     *
     * @param  Collection<int, BillingRecord>  $records
     * @return array<int, array{start: Carbon, end: Carbon, spans_two_months: bool, carry_line: ?string}>
     */
    public static function forRecords(Collection $records, Business $business): array
    {
        $ids = $records->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $bounds = [];

        foreach ([
            BillingEvent::query()->whereIn('billing_record_id', $ids),
            SmsCampaignRecipient::query()->whereIn('billing_record_id', $ids),
        ] as $query) {
            $rows = $query
                ->selectRaw('billing_record_id, MIN(created_at) as first_at, MAX(created_at) as last_at')
                ->groupBy('billing_record_id')
                ->get();

            foreach ($rows as $row) {
                $id = (int) $row->billing_record_id;
                $first = Carbon::parse($row->first_at);
                $last = Carbon::parse($row->last_at);
                $bounds[$id] = [
                    'first' => isset($bounds[$id]) && $bounds[$id]['first']->lt($first) ? $bounds[$id]['first'] : $first,
                    'last' => isset($bounds[$id]) && $bounds[$id]['last']->gt($last) ? $bounds[$id]['last'] : $last,
                ];
            }
        }

        $windows = [];

        foreach ($records as $record) {
            if (isset($bounds[$record->id])) {
                $start = $business->localTime($bounds[$record->id]['first']);
                $end = $business->localTime($bounds[$record->id]['last']);
            } else {
                $start = Carbon::parse($record->period_start);
                $end = Carbon::parse($record->period_end);
            }

            $spans = $start->format('Y-m') !== $end->format('Y-m');

            $windows[$record->id] = [
                'start' => $start,
                'end' => $end,
                'spans_two_months' => $spans,
                'carry_line' => $spans
                    ? 'This charge covers part of '.$start->format('F').' and part of '.$end->format('F')
                        .'. The earlier part carried forward into this charge.'
                    : null,
            ];
        }

        return $windows;
    }
}
