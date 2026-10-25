<?php

namespace Tests\Feature\Owner;

use App\Contracts\SmsGateway;
use App\Models\AuditLog;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\SmsMessage;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Customers (spec Section 5.2, Sprint 3): tenant scoping, search,
 * the add outcomes, owner-asserted consent provenance with its
 * one confirmation text, the YES confirmation leg, and removal
 * that ends the connection without deleting history.
 */
class CustomersTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    private FakeSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
    }

    public function test_list_shows_only_this_businesss_customers_with_masked_phones(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $other = World::business();
        World::membership($business, World::customer('+12125550201', 'Ana', 'Ray'));
        World::membership($other, World::customer('+12125550202', 'Zed', 'Other'));

        $response = $this->actingAs($owner)->get(route('customers.index'));

        $response->assertOk();
        $response->assertSee('Ana');
        $response->assertSee('*** *** 0201');
        $response->assertDontSee('Zed');
        $response->assertDontSee('2125550202');
        $response->assertDontSee('+12125550201');
    }

    public function test_search_matches_name_and_phone_digits(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::membership($business, World::customer('+12125550211', 'Marisol', 'Vega'));
        World::membership($business, World::customer('+12125550212', 'Peter', 'Hale'));

        $this->actingAs($owner)->get(route('customers.index', ['q' => 'Marisol']))
            ->assertSee('Marisol')->assertDontSee('Peter');
        $this->actingAs($owner)->get(route('customers.index', ['q' => '0212']))
            ->assertSee('Peter')->assertDontSee('Marisol');
    }

    public function test_add_customer_with_asserted_consent_sends_one_confirmation_text(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->post(route('customers.store'), [
            'first_name' => 'Nina',
            'last_name' => 'Park',
            'phone' => '(212) 555-0221',
            'consent' => '1',
        ]);

        $response->assertRedirect(route('customers.index'));
        $response->assertSessionHas('status', 'Customer added. Their referral link is on its way by text.');

        $customer = Customer::query()->where('phone_e164', '+12125550221')->firstOrFail();
        $membership = Membership::query()
            ->where('business_id', $business->id)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $this->assertTrue($membership->sms_consent);
        $this->assertSame('owner_asserted', $membership->consent_source);
        $this->assertTrue(ConsentEvent::query()
            ->where('membership_id', $membership->id)
            ->where('event_type', 'provenance_asserted')
            ->exists());
        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString('added you to Easy Refer', $this->sms->sent[0]['body']);
        $this->assertTrue(SmsMessage::query()->where('template_key', 'owner_add_confirmation')->exists());
    }

    public function test_add_customer_without_consent_sends_no_text(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('customers.store'), [
            'first_name' => 'Omar',
            'phone' => '2125550222',
        ])->assertSessionHas('status', 'Customer added.');

        $this->assertCount(0, $this->sms->sent);
        $membership = Membership::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertFalse($membership->sms_consent);
    }

    public function test_add_existing_customer_of_another_business_links_without_leaking(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $other = World::business();
        $person = World::customer('+12125550231', 'Secret', 'Person');
        World::membership($other, $person);

        $response = $this->actingAs($owner)->post(route('customers.store'), [
            'first_name' => 'Wrong',
            'last_name' => 'Name',
            'phone' => '2125550231',
            'consent' => '1',
        ]);

        $response->assertSessionHas('status', 'This customer already uses Easy Refer. They are now connected to your store.');
        // The existing record is never overwritten by another
        // tenant's form, and the other business stays invisible.
        $this->assertSame('Secret', $person->fresh()->first_name);
        $this->assertTrue(Membership::query()
            ->where('business_id', $business->id)
            ->where('customer_id', $person->id)
            ->exists());
    }

    public function test_detail_is_scoped_to_the_business(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $other = World::business();
        [$foreign] = World::membership($other, World::customer('+12125550241', 'Ana', 'Ray'));

        $this->actingAs($owner)->get(route('customers.show', $foreign))->assertNotFound();
    }

    public function test_remove_ends_connection_keeps_history_and_readd_restores(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550251', 'Ana', 'Ray'));
        World::referral($membership, World::customer('+12125550252', 'Fay', 'One'));

        $this->actingAs($owner)->delete(route('customers.destroy', $membership))->assertRedirect(route('customers.index'));

        $fresh = $membership->fresh();
        $this->assertNotNull($fresh->removed_at);
        $this->assertSame('revoked', $fresh->share_token_state);
        $this->assertSame(1, $business->referrals()->count());

        // The row is gone from the list (the removal flash names
        // her, so the masked phone is the absence probe).
        $this->actingAs($owner)->get(route('customers.index'))
            ->assertSee('Ana Ray was removed from your store.')
            ->assertDontSee('*** *** 0251');
        $this->actingAs($owner)->get(route('customers.show', $membership))->assertNotFound();

        $this->actingAs($owner)->post(route('customers.store'), [
            'first_name' => 'Ana',
            'phone' => '2125550251',
        ]);
        $this->assertNull($membership->fresh()->removed_at);
    }

    public function test_yes_reply_confirms_provenance_and_sends_the_share_link(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('customers.store'), [
            'first_name' => 'Nina',
            'phone' => '2125550261',
            'consent' => '1',
        ]);
        $this->sms->sent = [];

        $membership = Membership::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('owner_asserted', $membership->consent_source);

        $this->postTwilioWebhook(['From' => '+12125550261', 'Body' => 'YES', 'MessageSid' => 'SM_TEST_YES']);

        $fresh = $membership->fresh();
        $this->assertSame('customer_reply', $fresh->consent_source);
        $this->assertTrue($fresh->sms_consent);
        $this->assertTrue(ConsentEvent::query()
            ->where('membership_id', $membership->id)
            ->where('event_type', 'confirmed')
            ->exists());
        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString('Here is your referral link', $this->sms->sent[0]['body']);
    }

    public function test_search_is_literal_not_a_pattern(): void
    {
        // XT-46 (spec verifier finding 5): '%' and '_' are text,
        // not wildcards.
        $business = World::business();
        $owner = World::owner($business);
        World::membership($business, World::customer('+12125550271', 'Marisol', 'Vega'));
        World::membership($business, World::customer('+12125550272', 'Peter', 'Hale'));

        $this->actingAs($owner)->get(route('customers.index', ['q' => '%']))
            ->assertDontSee('Marisol')->assertDontSee('Peter')
            ->assertSee('No customers match that search.');
        $this->actingAs($owner)->get(route('customers.index', ['q' => '_']))
            ->assertDontSee('Marisol')->assertDontSee('Peter');
    }

    public function test_add_opted_out_phone_records_no_consent(): void
    {
        // Security verifier F-05: the add succeeds, but an
        // opted-out number gets no consent record, no provenance
        // event, and no text, whatever the owner asserts.
        $business = World::business();
        $owner = World::owner($business);
        \App\Models\SmsOptOut::create([
            'phone_e164' => '+12125550281',
            'opted_out_at' => now(),
            'source' => 'stop_reply',
        ]);

        $this->actingAs($owner)->post(route('customers.store'), [
            'first_name' => 'Nina',
            'phone' => '2125550281',
            'consent' => '1',
        ])->assertSessionHas('status', 'Customer added.');

        $membership = Membership::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertFalse($membership->sms_consent);
        $this->assertFalse(ConsentEvent::query()
            ->where('membership_id', $membership->id)
            ->where('event_type', 'provenance_asserted')
            ->exists());
        $this->assertCount(0, $this->sms->sent);
    }

    public function test_readd_of_active_member_applies_the_consent_assertion(): void
    {
        // Security verifier F-06: re-adding an existing member
        // with the consent box checked must not silently drop
        // the assertion.
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('customers.store'), [
            'first_name' => 'Nina',
            'phone' => '2125550291',
        ]);
        $membership = Membership::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertFalse($membership->sms_consent);

        $this->actingAs($owner)->post(route('customers.store'), [
            'first_name' => 'Nina',
            'phone' => '2125550291',
            'consent' => '1',
        ])->assertRedirect(route('customers.show', $membership));

        $this->assertTrue($membership->fresh()->sms_consent);
        $this->assertTrue(ConsentEvent::query()
            ->where('membership_id', $membership->id)
            ->where('event_type', 'provenance_asserted')
            ->exists());
        $this->assertCount(1, $this->sms->sent);
    }

    public function test_yes_reply_confirms_only_the_store_whose_text_it_answers(): void
    {
        // Spec verifier finding 8: consent provenance is
        // per-membership. Two stores text the same person; one
        // YES confirms only the store whose text it answers
        // (the most recent one), a second YES the next.
        $businessA = World::business();
        $ownerA = World::owner($businessA);
        $businessB = World::business();
        $ownerB = World::owner($businessB);

        $this->actingAs($ownerA)->post(route('customers.store'), [
            'first_name' => 'Nina', 'phone' => '2125550295', 'consent' => '1',
        ]);
        $this->actingAs($ownerB)->post(route('customers.store'), [
            'first_name' => 'Nina', 'phone' => '2125550295', 'consent' => '1',
        ]);
        $this->sms->sent = [];

        $membershipA = Membership::query()->where('business_id', $businessA->id)->firstOrFail();
        $membershipB = Membership::query()->where('business_id', $businessB->id)->firstOrFail();

        $this->postTwilioWebhook(['From' => '+12125550295', 'Body' => 'YES', 'MessageSid' => 'SM_TEST_YES_A']);

        $this->assertSame('owner_asserted', $membershipA->fresh()->consent_source);
        $this->assertSame('customer_reply', $membershipB->fresh()->consent_source);
        $this->assertCount(1, $this->sms->sent);

        $this->postTwilioWebhook(['From' => '+12125550295', 'Body' => 'YES', 'MessageSid' => 'SM_TEST_YES_B']);

        $this->assertSame('customer_reply', $membershipA->fresh()->consent_source);
        $this->assertCount(2, $this->sms->sent);
    }

    public function test_share_link_ledger_body_never_stores_the_live_token(): void
    {
        // Security verifier F-09: the gateway sends the full
        // link; the stored evidence body is redacted.
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('customers.store'), [
            'first_name' => 'Nina',
            'phone' => '2125550297',
            'consent' => '1',
        ]);
        $this->sms->sent = [];

        $membership = Membership::query()->where('business_id', $business->id)->firstOrFail();
        $this->postTwilioWebhook(['From' => '+12125550297', 'Body' => 'YES', 'MessageSid' => 'SM_TEST_YES_C']);

        $plain = $membership->fresh()->plainShareToken();
        $this->assertStringContainsString($plain, $this->sms->sent[0]['body']);

        $stored = SmsMessage::query()->where('purpose', 'share_link')->firstOrFail();
        $this->assertStringNotContainsString($plain, (string) $stored->rendered_body);
        $this->assertStringContainsString('[redacted]', (string) $stored->rendered_body);
    }

    // GAP 1 (A-080): name-only edit. The design's eight tests
    // (small-gaps-design.md Section 1.6).

    public function test_edit_page_renders_name_fields_and_readonly_phone(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550301', 'Ana', 'Ray'));

        $response = $this->actingAs($owner)->get(route('customers.edit', $membership));

        $response->assertOk();
        $response->assertSee('value="Ana"', false);
        $response->assertSee('value="Ray"', false);
        // The number renders as text (the show page's formatter),
        // never as an input.
        $response->assertSee('+1 (212) 555-0301');
        $response->assertDontSee('name="phone"', false);
        $response->assertSee('Phone numbers can\'t be changed here', false);
        $response->assertSee('Save changes');
    }

    public function test_owner_can_fix_a_customer_name_typo(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550302', 'Anaa', 'Ray'));

        $this->actingAs($owner)->put(route('customers.update', $membership), [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
        ])->assertRedirect(route('customers.show', $membership))
            ->assertSessionHas('status', 'Customer details saved.');

        $customer = $membership->customer->fresh();
        $this->assertSame('Ana', $customer->first_name);
        $this->assertSame('Reyes', $customer->last_name);

        $this->actingAs($owner)->get(route('customers.show', $membership))
            ->assertSee('Ana Reyes');
    }

    public function test_name_edit_is_global_across_businesses(): void
    {
        // Design 1.5: the name lives on the person, so one store's
        // typo fix renames the customer in every store's view.
        $business = World::business();
        $other = World::business();
        $owner = World::owner($business);
        $otherOwner = World::owner($other);
        $customer = World::customer('+12125550303', 'Anaa', 'Ray');
        [$membership] = World::membership($business, $customer);
        [$otherMembership] = World::membership($other, $customer);

        $this->actingAs($owner)->put(route('customers.update', $membership), [
            'first_name' => 'Ana',
            'last_name' => 'Ray',
        ])->assertRedirect();

        $this->actingAs($otherOwner)->get(route('customers.show', $otherMembership))
            ->assertSee('Ana Ray');
    }

    public function test_name_edit_leaves_consent_projection_untouched(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550304', 'Ana', 'Ray'));
        $membership->forceFill([
            'sms_consent' => true,
            'consent_source' => 'customer_reply',
            'consent_updated_at' => now()->subDays(3),
        ])->save();
        $consentUpdatedAt = $membership->fresh()->consent_updated_at;
        $tokenHash = $membership->share_token_hash;
        $eventCount = ConsentEvent::count();

        $this->actingAs($owner)->put(route('customers.update', $membership), [
            'first_name' => 'Anna',
            'last_name' => 'Ray',
        ])->assertRedirect();

        $fresh = $membership->fresh();
        $this->assertTrue($fresh->sms_consent);
        $this->assertSame('customer_reply', $fresh->consent_source);
        $this->assertTrue($consentUpdatedAt->equalTo($fresh->consent_updated_at));
        $this->assertSame($tokenHash, $fresh->share_token_hash);
        $this->assertSame($eventCount, ConsentEvent::count());
    }

    public function test_name_edit_writes_one_audit_row_with_old_and_new(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550305', 'Anaa', 'Ray'));

        $this->actingAs($owner)->put(route('customers.update', $membership), [
            'first_name' => 'Ana',
            'last_name' => 'Ray',
        ])->assertRedirect();

        $rows = AuditLog::query()->where('action', 'customer.details_updated')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($membership->id, (int) $rows[0]->target_id);
        $this->assertSame(['Anaa', 'Ana'], $rows[0]->metadata['first_name']);
        $this->assertSame(['first_name'], $rows[0]->metadata['changed']);

        // A no-op save writes no second row.
        $this->actingAs($owner)->put(route('customers.update', $membership), [
            'first_name' => 'Ana',
            'last_name' => 'Ray',
        ])->assertRedirect(route('customers.show', $membership))
            ->assertSessionHas('status', 'Customer details saved.');
        $this->assertCount(1, AuditLog::query()->where('action', 'customer.details_updated')->get());
    }

    public function test_edit_requires_first_name_and_caps_length(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550306', 'Ana', 'Ray'));

        $this->actingAs($owner)->put(route('customers.update', $membership), [
            'first_name' => '',
            'last_name' => 'Ray',
        ])->assertSessionHasErrors(['first_name' => 'Enter the customer name.']);

        $this->actingAs($owner)->put(route('customers.update', $membership), [
            'first_name' => str_repeat('a', 101),
        ])->assertSessionHasErrors(['first_name' => 'That entry is too long. Shorten it and try again.']);

        $this->assertSame('Ana', $membership->customer->fresh()->first_name);
    }

    public function test_edit_is_scoped_to_the_business(): void
    {
        $business = World::business();
        $other = World::business();
        $owner = World::owner($business);
        [$foreign] = World::membership($other, World::customer('+12125550307', 'Zed', 'Other'));
        [$removed] = World::membership($business, World::customer('+12125550308', 'Gone', 'Girl'));
        $removed->forceFill(['removed_at' => now()])->save();

        $this->actingAs($owner)->get(route('customers.edit', $foreign))->assertNotFound();
        $this->actingAs($owner)->put(route('customers.update', $foreign), [
            'first_name' => 'Hacked',
        ])->assertNotFound();
        $this->actingAs($owner)->get(route('customers.edit', $removed))->assertNotFound();
        $this->actingAs($owner)->put(route('customers.update', $removed), [
            'first_name' => 'Back',
        ])->assertNotFound();

        $this->assertSame('Zed', $foreign->customer->fresh()->first_name);
    }

    public function test_phone_cannot_be_changed_through_update(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550309', 'Ana', 'Ray'));

        $this->actingAs($owner)->put(route('customers.update', $membership), [
            'first_name' => 'Ana',
            'last_name' => 'Ray',
            'phone' => '2125559999',
        ])->assertRedirect();

        $this->assertSame('+12125550309', $membership->customer->fresh()->phone_e164);
    }

    private function postTwilioWebhook(array $params): void
    {
        \Illuminate\Support\Facades\Config::set('services.twilio.webhook_auth_token', 'test-auth-token');

        $url = route('webhooks.twilio.status');
        $validator = new \Twilio\Security\RequestValidator('test-auth-token');
        $signature = $validator->computeSignature($url, $params);

        $this->call('POST', $url, $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $signature])
            ->assertOk();
    }
}
