<?php

namespace Tests\Feature\Owner;

use App\Models\AuditLog;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Settings (spec Section 5.5, Sprint 3): every page saves what it
 * promises, validates the way signup does (AUTH-45's settings
 * leg), and audits. The Google review URL rule and the velocity
 * bounds are exercised at their edges.
 */
class SettingsTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    public function test_profile_save_round_trips_and_audits(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('settings.profile.update'), [
            'name' => 'Renamed Shop',
            'business_type' => 'salon',
            'contact_email' => 'hello@example.test',
            'contact_phone' => '(212) 555-0401',
            'street' => '9 New Street',
            'city' => 'Albany',
            'state' => 'NY',
            'postal_code' => '12207',
            'country' => 'US',
        ])->assertSessionHas('status', 'Business profile saved.');

        $fresh = $business->fresh();
        $this->assertSame('Renamed Shop', $fresh->name);
        $this->assertSame('salon', $fresh->business_type);
        $this->assertSame('+12125550401', $fresh->contact_phone_e164);
        $this->assertTrue(AuditLog::query()->where('action', 'business.profile_updated')->exists());
    }

    public function test_auth45_settings_leg_rejects_oversized_input_without_a_500(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $originalName = $business->name;
        $huge = str_repeat('x', 10000);

        $this->actingAs($owner)->post(route('settings.profile.update'), [
            'name' => $huge,
            'contact_email' => 'hello@example.test',
            'contact_phone' => '2125550402',
            'street' => $huge,
            'city' => 'Albany',
            'state' => 'NY',
            'postal_code' => '12207',
            'country' => 'US',
        ])->assertSessionHasErrors(['name', 'street']);

        $this->actingAs($owner)->post(route('settings.rewards.update'), [
            'friend_reward_text' => $huge,
            'redemption_window_days' => 30,
        ])->assertSessionHasErrors('friend_reward_text');

        $this->assertSame($originalName, $business->fresh()->name);
        $this->assertSame('$10 off your first visit', $business->fresh()->friend_reward_text);
    }

    public function test_rewards_save_texts_window_and_the_referrer_default(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('settings.rewards.update'), [
            'friend_reward_text' => 'Free pastry with your first coffee',
            'referrer_reward_text' => '',
            'redemption_window_days' => 45,
        ])->assertSessionHas('status', 'Rewards saved.');

        $fresh = $business->fresh();
        $this->assertSame('Free pastry with your first coffee', $fresh->friend_reward_text);
        $this->assertNull($fresh->referrer_reward_text);
        $this->assertSame(45, $fresh->redemption_window_days);
    }

    public function test_value_save_converts_dollars_to_cents(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('settings.value.update'), [
            'average_ticket' => '42.50',
        ])->assertSessionHas('status', 'Value saved.');

        $this->assertSame(4250, $business->fresh()->average_ticket_cents);
    }

    public function test_review_url_shape_rule(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        foreach (['https://g.page/r/Cabc123/review', 'https://maps.app.goo.gl/xyz', 'https://www.google.com/maps/place/x'] as $good) {
            $this->actingAs($owner)->post(route('settings.reviews.update'), [
                'google_review_url' => $good,
            ])->assertSessionHas('status', 'Review link saved.');
            $this->assertSame($good, $business->fresh()->google_review_url);
        }

        foreach (['http://g.page/r/Cabc123/review', 'https://example.com/review', 'https://google.com.evil.test/review', 'not a url'] as $bad) {
            $this->actingAs($owner)->post(route('settings.reviews.update'), [
                'google_review_url' => $bad,
            ])->assertSessionHasErrors('google_review_url');
        }

        // Clearing the field removes the link.
        $this->actingAs($owner)->post(route('settings.reviews.update'), [
            'google_review_url' => '',
        ])->assertSessionHas('status', 'Review link saved.');
        $this->assertNull($business->fresh()->google_review_url);
    }

    public function test_review_url_backslash_and_userinfo_are_rejected_and_storage_is_canonical(): void
    {
        // Security verifier F-01: PHP and the browser parse
        // "https://evil.com\@g.page/x" differently; the allowlist
        // must refuse it, along with userinfo shapes, and what is
        // stored is the canonical rebuild of what passed.
        $business = World::business();
        $owner = World::owner($business);

        foreach (['https://evil.com\@g.page/x', 'https://evil@g.page/r/x', 'https://g.page:8443/r/x'] as $bad) {
            $this->actingAs($owner)->post(route('settings.reviews.update'), [
                'google_review_url' => $bad,
            ])->assertSessionHasErrors('google_review_url');
            $this->assertNull($business->fresh()->google_review_url);
        }

        $this->actingAs($owner)->post(route('settings.reviews.update'), [
            'google_review_url' => 'https://G.Page/r/ABC123',
        ])->assertSessionHas('status', 'Review link saved.');
        $this->assertSame('https://g.page/r/ABC123', $business->fresh()->google_review_url);
    }

    public function test_notification_toggles_map_to_the_spec_columns(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('settings.notifications.update'), [
            'owner_text_notifications' => '1',
            // weekly_summary and nudges absent: both opt-outs on.
        ])->assertSessionHas('status', 'Notification settings saved.');

        $fresh = $business->fresh();
        $this->assertTrue($fresh->owner_text_notifications);
        $this->assertTrue($fresh->weekly_digest_opt_out);
        $this->assertTrue($fresh->nudge_opt_out);

        $this->actingAs($owner)->post(route('settings.notifications.update'), [
            'weekly_summary' => '1',
            'nudges' => '1',
        ]);

        $fresh = $business->fresh();
        $this->assertFalse($fresh->owner_text_notifications);
        $this->assertFalse($fresh->weekly_digest_opt_out);
        $this->assertFalse($fresh->nudge_opt_out);
    }

    public function test_velocity_limits_validate_against_platform_bounds(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('settings.limits.update'), [
            'daily_referral_cap' => 10,
            'monthly_referral_cap' => 120,
        ])->assertSessionHas('status', 'Referral limits saved.');

        $this->assertSame(10, $business->fresh()->daily_referral_cap);
        $this->assertSame(120, $business->fresh()->monthly_referral_cap);

        $this->actingAs($owner)->post(route('settings.limits.update'), [
            'daily_referral_cap' => 0,
            'monthly_referral_cap' => 99999,
        ])->assertSessionHasErrors(['daily_referral_cap', 'monthly_referral_cap']);
    }

    public function test_settings_pages_render_and_security_is_under_settings_navigation(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        foreach (['settings', 'settings.profile', 'settings.rewards', 'settings.value', 'settings.reviews', 'settings.notifications', 'settings.limits', 'settings.account'] as $routeName) {
            $this->actingAs($owner)->get(route($routeName))->assertOk();
        }

        $this->actingAs($owner)->get(route('security'))
            ->assertOk()
            ->assertSee('Business profile')
            ->assertSee(route('settings.profile'), false);
    }
}
