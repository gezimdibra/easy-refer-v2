<?php

namespace Tests\Feature;

use App\Models\ReferralEvent;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The release 08b lane B gate (brief Section 5.10): the fixes
 * that used to live only in audit prose get rendered-output
 * assertions, so the floors and states stop depending on the
 * next audit to be noticed. Heights are asserted through the
 * utility classes that produce them (the census classes are
 * the contract), states through the strings each state owns.
 */
class DesignFixGateTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_shared_chrome_carries_the_tap_target_floors(): void
    {
        // DG-4: the footer's text rows stand 48px (they measured
        // 35px) and the social buttons stand 48px (they were 36).
        $home = $this->get(route('home'));
        $home->assertOk();
        $this->assertSame(10, substr_count($home->getContent(), 'flex min-h-12 items-center text-[15px]'));
        $this->assertSame(4, substr_count($home->getContent(), 'flex h-12 w-12 items-center justify-center rounded-full'));
        // DG-4: the desktop header nav links stand 48px too.
        $this->assertSame(5, substr_count($home->getContent(), 'inline-flex min-h-12 items-center rounded-md px-3'));

        // TX-D4: the auth legal links clear the 24px floor and
        // TX-D4's named link, Forgot password?, stands 44px.
        $login = $this->get(route('login'));
        $login->assertOk();
        $this->assertSame(3, substr_count($login->getContent(), 'inline-flex min-h-6 items-center'));
        $login->assertSee('inline-flex min-h-11 items-center font-medium text-brand-700 underline', false);
    }

    public function test_app_shell_menu_buttons_and_mobile_brand_mark(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();
        // DG-4: both menu buttons (open in the bar, close in the
        // slide-over) stand 48px.
        $this->assertSame(2, substr_count($response->getContent(), 'h-12 w-12 items-center justify-center rounded-md'));
        // D-8: the companion mark (the wordmark-free tile, the
        // 48-box viewBox) renders in the mobile bar.
        $response->assertSee('viewBox="0 0 48 48"', false);
    }

    public function test_data_loading_rides_the_named_forms(): void
    {
        // DG-3: the submit-state sweep. Each page's named form
        // carries the opt-in attribute the shared handler keys on.
        $this->get(route('login'))->assertSee('data-loading', false);
        $this->get(route('register'))->assertSee('data-loading', false);
        $this->get(route('password.request'))->assertSee('data-loading', false);

        $business = World::business();
        $owner = World::owner($business);

        // The checklist dismiss form on the dashboard.
        $this->actingAs($owner)->get(route('dashboard'))->assertSee('data-loading', false);
        // The settings update form and the security forms.
        $this->actingAs($owner)->get(route('settings.profile'))->assertSee('data-loading', false);
        $this->actingAs($owner)->get(route('security'))->assertSee('data-loading', false);
        // The customers search (a GET form that navigates, named
        // in the sweep).
        $this->actingAs($owner)->get(route('customers.index'))->assertSee('data-loading', false);
    }

    public function test_confirm_modal_focus_lands_inside_the_dialog(): void
    {
        // DG-6: the focus-inside assertion the brief asks for.
        // The QR page carries a confirm modal (Replace this
        // code); its show() must carry the frame-deferred focus
        // onto the Cancel ref, and the raced single-tick form
        // must be gone.
        $business = World::business();
        $owner = World::owner($business);
        World::qr($business);

        $response = $this->actingAs($owner)->get(route('owner.qr'));
        $response->assertOk();
        $response->assertSee('requestAnimationFrame(() => this.$refs.cancel.focus())', false);
        $response->assertSee('x-ref="cancel"', false);
        $response->assertDontSee('$nextTick(() => this.$refs.cancel.focus())', false);
    }

    public function test_pagination_uses_the_product_chrome(): void
    {
        // DG-8: past one page of customers, the pagination
        // renders the product view: ghost-button geometry and
        // the Lucide chevron, and none of the framework's filled
        // glyph paths survive.
        $business = World::business();
        $owner = World::owner($business);
        for ($i = 0; $i < 26; $i++) {
            World::membership($business, World::customer('+1212555'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'Cust', 'N'.$i));
        }

        $response = $this->actingAs($owner)->get(route('customers.index'));
        $response->assertOk();
        $response->assertSee('btn btn-ghost', false);
        $response->assertSee('m9 18 6-6-6-6', false);
        $response->assertDontSee('M12.707 5.293', false);
        $response->assertDontSee('M7.293 14.707', false);
    }

    public function test_dashboard_setup_furniture_gates_on_the_poster_step(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        // Poster step open: the full action card leads and the
        // checklist sits in its first position, above the tiles.
        $before = $this->actingAs($owner)->get(route('dashboard'));
        $before->assertOk();
        $before->assertSee('<h2 class="type-title-3 text-text-1">Print your QR code</h2>', false);
        $this->assertLessThan(
            strpos($before->getContent(), 'Scans today'),
            strpos($before->getContent(), 'Setup checklist')
        );

        // Poster printed: the card collapses to its slim form
        // (no headline form) and the checklist drops below the
        // tiles, still rendered while other steps are open.
        ReferralEvent::create([
            'business_id' => $business->id,
            'event_type' => 'poster_downloaded',
            'actor' => 'owner',
        ]);
        $after = $this->actingAs($owner)->get(route('dashboard'));
        $after->assertOk();
        $after->assertDontSee('<h2 class="type-title-3 text-text-1">Print your QR code</h2>', false);
        $after->assertSee('Setup checklist');
        $this->assertGreaterThan(
            strpos($after->getContent(), 'Scans today'),
            strpos($after->getContent(), 'Setup checklist')
        );
    }

    public function test_checklist_rows_share_one_state_pattern(): void
    {
        // DG-2: no hand-set glyphs remain in the checklist (the
        // 11px "!" spans and the bare check character), the open
        // rows carry the Lucide alert circle, and the poster
        // rows render as full-width 48px rows with held arrows.
        $business = World::business();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();
        $response->assertDontSee('font-size: 11px', false);
        $response->assertDontSee('<span aria-hidden="true">✓</span>', false);
        $response->assertSee('<line x1="12" x2="12" y1="8" y2="12" />', false);
        $response->assertSee('flex min-h-12 items-center gap-1.5 font-semibold text-brand-700', false);
        $response->assertSee('flex-none', false);
    }
}
