<?php

namespace Tests\Feature\Owner;

use App\Models\ReferralEvent;
use App\Support\SetupChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Q2 (coordinator ruling on the coherence audit's G9): the
 * checklist's poster step completes when the owner PRINTS or
 * DOWNLOADS the artifact — the observable act of taking it —
 * never on a bare poster page view. The same event drives
 * DG-2's dashboard demotion, so a view can neither finish
 * the step nor demote the setup furniture around it.
 */
class PosterPrintCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function business()
    {
        return World::business([
            'billing_status' => 'none',
            'google_review_url' => null,
            'square_customer_id' => null,
        ]);
    }

    private function posterStepDone($business, $owner): bool
    {
        return SetupChecklist::for($business->fresh(), $owner)['poster_downloaded'];
    }

    public function test_a_bare_poster_view_records_nothing_and_completes_nothing(): void
    {
        $business = $this->business();
        $owner = World::owner($business);

        foreach (['counter', 'window', 'tent'] as $variant) {
            $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => $variant]))
                ->assertOk();
        }

        $this->assertSame(0, ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'poster_downloaded')
            ->count());
        $this->assertFalse($this->posterStepDone($business, $owner));
    }

    public function test_opening_a_poster_in_print_mode_completes_the_step(): void
    {
        $business = $this->business();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'counter', 'print' => 1]))
            ->assertOk();

        $this->assertSame(1, ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'poster_downloaded')
            ->count());
        $this->assertTrue($this->posterStepDone($business, $owner));
    }

    public function test_the_code_download_completes_the_step(): void
    {
        $business = $this->business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('owner.qr.download'))->assertOk();

        $this->assertTrue($this->posterStepDone($business, $owner));
    }

    public function test_dg2_demotion_waits_for_a_real_print(): void
    {
        $business = $this->business();
        $owner = World::owner($business);

        // A bare view: the dashboard keeps the full QR action
        // card (its standing work is not done).
        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'counter']))->assertOk();

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Posters at the register get scanned. Posters in a drawer do not.');

        // A print-mode open: the card collapses to the slim
        // link row, as DG-2 designed — only now.
        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'counter', 'print' => 1]))->assertOk();

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Posters at the register get scanned. Posters in a drawer do not.');
    }
}
