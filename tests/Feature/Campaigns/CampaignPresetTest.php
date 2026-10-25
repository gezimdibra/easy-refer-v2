<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsOptOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * LV-01 (Train 2; copy Amendment 22 CAMP-16..21): campaign
 * presets, duplicate, and the confirm preflight. A preset is
 * a button on the composer that creates a draft with the
 * ready-made body and lands on the confirm screen; the preset
 * bodies carry no system prefix or STOP suffix (those are
 * locked system parts, rendered around the body). Duplicate
 * carries the body only — the audience always re-derives from
 * the eligibility rule, never from the old campaign. The
 * confirm screen gains the "Before you send" preflight: the
 * CAMP-09 exclusion breakdown, moved up from the composer.
 */
class CampaignPresetTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSequence = 4100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
    }

    private function member(Business $business, array $consent = ['sms_consent' => true, 'consent_source' => 'customer_checkbox']): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill($consent)->save();

        return $membership->fresh();
    }

    public function test_composer_offers_the_three_presets_by_name(): void
    {
        $business = World::business();
        $this->member($business);

        $this->actingAs(World::owner($business))->get(route('campaigns.create'))
            ->assertOk()
            ->assertSee('Start from a ready-made message')
            ->assertSee('Thank your customers and ask for one referral')
            ->assertSee('Fill a slow day')
            ->assertSee('We miss you');
    }

    public function test_choosing_a_preset_creates_a_draft_and_lands_on_confirm(): void
    {
        $business = World::business();
        $this->member($business);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'slow_day']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $response->assertRedirect(route('campaigns.confirm', $campaign));
        $this->assertSame(
            'We have room today and would love to see you. Stop by, and bring a friend along: when they visit, you both get a reward.',
            $campaign->body,
        );
        $this->assertSame(SmsCampaign::STATUS_DRAFT, $campaign->status);
        $this->assertStringStartsNotWith($business->name.': ', $campaign->body);
        $this->assertStringNotContainsString('STOP', $campaign->body);

        // The confirm screen renders the preset body inside the
        // locked system parts.
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            ->assertSee('We have room today and would love to see you.');
    }

    public function test_unknown_preset_is_rejected(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'not_a_preset'])
            ->assertSessionHasErrors('preset');
        $this->assertSame(0, SmsCampaign::query()->where('business_id', $business->id)->count());
    }

    public function test_duplicate_copies_the_body_and_rederives_the_audience(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->member($business);
        $this->member($business);

        $original = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id,
        ]);

        // The audience moves after the original sent: one
        // member opts out, one new member consents.
        $member = $business->memberships()->first();
        SmsOptOut::create(['phone_e164' => $member->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);
        $this->member($business);

        $response = $this->actingAs($owner)->post(route('campaigns.duplicate', $original));
        $copy = SmsCampaign::query()->where('business_id', $business->id)
            ->whereKeyNot($original->id)->latest('id')->firstOrFail();

        $response->assertRedirect(route('campaigns.confirm', $copy));
        $this->assertSame('Spring sale is on', $copy->body);
        $this->assertSame(SmsCampaign::STATUS_DRAFT, $copy->status);
        $this->assertSame(0, $copy->recipients()->count());

        // Eligible now: 2 (3 consented minus 1 opted out), not
        // the original's 2-recipient snapshot by coincidence of
        // count but by re-derivation: the confirm page states it.
        $this->actingAs($owner)->get(route('campaigns.confirm', $copy))
            ->assertOk()
            ->assertSee('Sending to 2 customers who agreed to receive messages.');
    }

    public function test_duplicate_of_a_draft_is_a_404_and_other_tenants_are_isolated(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $draft = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Still drafting',
            'status' => SmsCampaign::STATUS_DRAFT,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)->post(route('campaigns.duplicate', $draft))->assertNotFound();

        $other = World::business();
        $sent = SmsCampaign::create([
            'business_id' => $other->id,
            'body' => 'Other business body',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => World::owner($other)->id,
        ]);

        $this->actingAs($owner)->post(route('campaigns.duplicate', $sent))->assertNotFound();
    }

    public function test_confirm_preflight_shows_who_will_not_get_the_text(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->member($business);
        $this->member($business, ['sms_consent' => true, 'consent_source' => 'owner_asserted']);
        $out = $this->member($business);
        SmsOptOut::create(['phone_e164' => $out->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $this->actingAs($owner)->post(route('campaigns.store'), ['body' => 'Spring sale is on'])->assertRedirect();
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            ->assertSee('Before you send')
            ->assertSee('Not counted: 1 added by you and not yet confirmed, 1 opted out, 0 with no consent on file.');
    }
}
