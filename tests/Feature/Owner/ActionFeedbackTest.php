<?php

namespace Tests\Feature\Owner;

use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Contracts\MarketingSmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RS-14 (Train 2; copy Amendment 22 GEN-01..05): action
 * feedback. Reversible actions (remove customer, change
 * reward texts) complete instantly and carry a 30-second
 * Undo on their success toast; undo restores the prior state
 * byte-for-byte, and an expired or replayed undo flashes
 * GEN-02. The campaign send — irreversible — gets the
 * GEN-05 scoped confirmation and the GEN-04 outcome toast
 * with a View results link.
 *
 * Fixture note: the undo token is read from the flashed
 * toast immediately after the action response — the next
 * request ages flash data out, exactly as a browser's would
 * be (the server-side snapshot itself is regular session
 * data and outlives the flash).
 */
class ActionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSequence = 800;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_remove_customer_toast_carries_undo_and_undo_restores_byte_for_byte(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $customer = World::customer('+12125550701', 'Jamie', 'Rivera');
        [$membership] = World::membership($business, $customer);
        $tokenBefore = $membership->share_token_hash;
        $stateBefore = $membership->share_token_state;

        $this->actingAs($owner)
            ->delete(route('customers.destroy', $membership))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('status', 'Jamie Rivera was removed from your store.');

        $removed = $membership->fresh();
        $this->assertNotNull($removed->removed_at);
        $this->assertSame('revoked', $removed->share_token_state);

        $token = session('toast')['undo']['token'];

        // The toast renders on the landing page with the Undo
        // action; the token rides the form body, never a URL.
        $this->actingAs($owner)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Jamie Rivera was removed from your store.')
            ->assertSee('Undo')
            ->assertSee('name="undo_token"', false);

        $this->actingAs($owner)
            ->post(route('actions.undo'), ['undo_token' => $token])
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('status', 'Jamie Rivera is back in your store.');

        $restored = $membership->fresh();
        $this->assertNull($restored->removed_at);
        $this->assertSame($stateBefore, $restored->share_token_state);
        $this->assertSame($tokenBefore, $restored->share_token_hash);
    }

    public function test_undo_after_the_window_and_replayed_undo_flash_gen_02(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $customer = World::customer('+12125550702', 'Jamie', 'Rivera');
        [$membership] = World::membership($business, $customer);

        $this->actingAs($owner)->delete(route('customers.destroy', $membership));
        $token = session('toast')['undo']['token'];

        // 31 seconds later the window is shut.
        Carbon::setTestNow(now()->addSeconds(31));

        $this->actingAs($owner)
            ->post(route('actions.undo'), ['undo_token' => $token])
            ->assertSessionHas('status', 'That can no longer be undone.');
        $this->assertNotNull($membership->fresh()->removed_at);

        // A fresh removal, undone once, cannot be undone twice.
        Carbon::setTestNow();
        $this->actingAs($owner)->delete(route('customers.destroy', $membership->fresh()));
        $token2 = session('toast')['undo']['token'];

        $this->actingAs($owner)
            ->post(route('actions.undo'), ['undo_token' => $token2])
            ->assertSessionHas('status', 'Jamie Rivera is back in your store.');
        $this->actingAs($owner)
            ->post(route('actions.undo'), ['undo_token' => $token2])
            ->assertSessionHas('status', 'That can no longer be undone.');
    }

    public function test_reward_texts_change_carries_undo_and_restores_the_prior_pair(): void
    {
        $business = World::business([
            'friend_reward_text' => 'Free coffee for your friend',
            'referrer_reward_text' => 'Free coffee for you',
        ]);
        $owner = World::owner($business);

        $payload = [
            'friend_reward_text' => 'Free pastry for your friend',
            'referrer_reward_text' => 'Free pastry for you',
            'redemption_window_days' => $business->redemption_window_days,
            'reward_vesting_days' => $business->reward_vesting_days,
        ];

        $this->actingAs($owner)
            ->post(route('settings.rewards.update'), $payload)
            ->assertSessionHas('status', 'Rewards saved.');

        $token = session('toast')['undo']['token'];

        $this->actingAs($owner)->get(route('settings.rewards'))
            ->assertOk()
            ->assertSee('Rewards saved.')
            ->assertSee('Undo');

        $this->actingAs($owner)
            ->post(route('actions.undo'), ['undo_token' => $token])
            ->assertSessionHas('status', 'Your reward changes were undone.');

        $fresh = $business->fresh();
        $this->assertSame('Free coffee for your friend', $fresh->friend_reward_text);
        $this->assertSame('Free coffee for you', $fresh->referrer_reward_text);
        // The undo restores the texts only: the rest of the
        // save (windows, vesting) stands.
        $this->assertSame($business->redemption_window_days, $fresh->redemption_window_days);
    }

    public function test_campaign_confirm_scopes_the_send_button_and_scope_line(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->member($business);
        $this->member($business);
        $campaign = $this->draft($business);

        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            ->assertSee('Total $0.04 for 2 customers. Once sent, this text cannot be unsent.')
            ->assertSee('Send to 2 customers')
            ->assertDontSee('>Send now<', false);
    }

    public function test_campaign_send_flashes_the_outcome_with_a_view_link(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->member($business);
        $this->member($business);
        $campaign = $this->draft($business);

        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))->assertOk();

        $this->actingAs($owner)
            ->post(route('campaigns.send', $campaign))
            ->assertRedirect(route('campaigns.show', $campaign))
            ->assertSessionHas('status', 'Campaign sent to 2 customers.');

        $this->actingAs($owner)->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Campaign sent to 2 customers.')
            ->assertSee('View results')
            ->assertSee('href="'.route('campaigns.show', $campaign).'"', false);
    }

    private function member(Business $business, array $consent = ['sms_consent' => true, 'consent_source' => 'customer_checkbox']): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill($consent)->save();

        return $membership->fresh();
    }

    private function draft(Business $business, string $body = 'Spring sale is on'): SmsCampaign
    {
        $owner = World::owner($business);
        $response = $this->actingAs($owner)->post(route('campaigns.store'), ['body' => $body]);
        $response->assertRedirect();

        return SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();
    }
}
