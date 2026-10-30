<?php

namespace Tests\Unit;

use App\Models\Business;
use App\Models\Referral;
use App\Services\Campaigns\SlowDayAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-01 (Amendment 46): the section 1.3 floor computation.
 * The analyzer runs DORMANT in this build: it is recorded,
 * and its output reaches no rendered surface (the invisibility
 * pin lives in the feature test). These tests pin the floor
 * arithmetic itself, for the post-launch candidate.
 */
class SlowDayAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 6100;

    private function visit(Business $business, Carbon $when): void
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $friendPhone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$referral] = World::referral($membership, World::customer($friendPhone));
        $referral->forceFill([
            'state' => Referral::STATE_REDEEMED,
            'created_at' => $when,
            'redeemed_at' => $when,
        ])->save();
    }

    /**
     * Seed the trailing 8 complete weeks (now = Wed 2026-10-28,
     * so the window is Mon 2026-08-31 .. Sun 2026-10-25 local):
     * every weekday gets 10 visits except Tuesday, which gets
     * 2 per week split evenly across the halves (16 total is
     * above the 60% margin line; per-week counts below tune it).
     *
     * @param  array<int, int>  $perWeekByWeekday  weekday (0=Sun..6=Sat) => visits in EACH of the 8 weeks
     */
    private function seedWeeks(Business $business, array $perWeekByWeekday): void
    {
        $windowStart = Carbon::parse('2026-08-31 12:00:00', 'America/New_York');
        for ($week = 0; $week < 8; $week++) {
            foreach ($perWeekByWeekday as $weekday => $count) {
                for ($i = 0; $i < $count; $i++) {
                    // Carbon dayOfWeek: 0=Sunday. Offset from Monday-start window.
                    $offset = ($weekday + 6) % 7;
                    $this->visit($business, $windowStart->copy()->addDays($week * 7 + $offset)->addHours($i));
                }
            }
        }
    }

    public function test_floor_passes_for_a_stable_genuinely_slow_weekday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));
        $business = World::business();
        // 10/week on six weekdays (80 each), 1/week Tuesday (8):
        // volume 488 >= 56, Tuesday 8 <= 60% of avg (~69), split-half stable.
        $this->seedWeeks($business, [0 => 10, 1 => 10, 2 => 1, 3 => 10, 4 => 10, 5 => 10, 6 => 10]);
        // A first visit long before the window (history span).
        $this->visit($business, Carbon::parse('2026-06-01 12:00:00', 'America/New_York'));

        $result = SlowDayAnalyzer::analyze($business);
        $this->assertSame(2, $result['candidate_weekday']);
        $this->assertTrue($result['passes']);
        Carbon::setTestNow();
    }

    public function test_low_volume_never_names_a_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));
        $business = World::business();
        $this->seedWeeks($business, [0 => 1, 1 => 1, 2 => 0, 3 => 1, 4 => 1, 5 => 1, 6 => 1]);

        $result = SlowDayAnalyzer::analyze($business);
        $this->assertFalse($result['passes']);
        $this->assertNull($result['candidate_weekday']);
        Carbon::setTestNow();
    }

    public function test_closed_monday_trap_a_zero_heavy_weekday_is_never_named(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));
        $business = World::business();
        // Monday (1) is zero every week: most likely closed.
        // Everything else uniform: no honest candidate exists.
        $this->seedWeeks($business, [0 => 10, 1 => 0, 2 => 10, 3 => 10, 4 => 10, 5 => 10, 6 => 10]);
        $this->visit($business, Carbon::parse('2026-06-01 12:00:00', 'America/New_York'));

        $result = SlowDayAnalyzer::analyze($business);
        $this->assertFalse($result['passes']);
        $this->assertNull($result['candidate_weekday']);
        Carbon::setTestNow();
    }

    public function test_split_half_noise_winner_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'America/New_York'));
        $business = World::business();
        // Tuesday slow only in the FIRST half; uniform second half.
        $windowStart = Carbon::parse('2026-08-31 12:00:00', 'America/New_York');
        for ($week = 0; $week < 8; $week++) {
            for ($day = 0; $day < 7; $day++) {
                $count = 10;
                if ($day === 1 && $week < 4) { // Tuesday offset from Monday = 1
                    $count = 1;
                }
                for ($i = 0; $i < $count; $i++) {
                    $this->visit($business, $windowStart->copy()->addDays($week * 7 + $day)->addHours($i));
                }
            }
        }
        $this->visit($business, Carbon::parse('2026-06-01 12:00:00', 'America/New_York'));

        $result = SlowDayAnalyzer::analyze($business);
        $this->assertFalse($result['passes']);
        Carbon::setTestNow();
    }

    public function test_analyze_and_record_writes_a_log_row(): void
    {
        $business = World::business();
        $log = SlowDayAnalyzer::analyzeAndRecord($business);
        $this->assertNotNull($log);
        $this->assertSame(1, \App\Models\SlowDayAnalyzerLog::query()->where('business_id', $business->id)->count());
        // A second call in the same week does not duplicate.
        SlowDayAnalyzer::analyzeAndRecord($business);
        $this->assertSame(1, \App\Models\SlowDayAnalyzerLog::query()->where('business_id', $business->id)->count());
    }
}
