<?php

namespace Tests\Feature\Owner;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Customer forms (post-r26 gap lane B2, design pack
 * 02-customer-forms, Variant A; Copy Amendment 29 [CF-1..CF-4]):
 * CF-1 one shared form renders Add and Edit, CF-2 the shared
 * error-summary component, CF-3 the consent switch row, CF-4
 * the Edit identity header and phone fact row, CF-5 the sticky
 * mobile footer and the duplicate phone slot.
 */
class CustomerFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_and_edit_share_group_headings_field_order_and_footer(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550401', 'Ana', 'Ray'));

        $add = $this->actingAs($owner)->get(route('customers.create'))->assertOk()->getContent();
        $edit = $this->actingAs($owner)->get(route('customers.edit', $membership))->assertOk()->getContent();

        foreach (['add' => $add, 'edit' => $edit] as $page => $html) {
            $nameAt = strpos($html, '>Name<');
            $phoneGroupAt = strpos($html, '>Phone and texts<');
            $firstAt = strpos($html, 'name="first_name"');
            $lastAt = strpos($html, 'name="last_name"');
            $this->assertNotFalse($nameAt, "{$page}: missing Name group heading");
            $this->assertNotFalse($phoneGroupAt, "{$page}: missing Phone and texts group heading");
            $this->assertLessThan($phoneGroupAt, $nameAt, "{$page}: group order");
            $this->assertLessThan($lastAt, $firstAt, "{$page}: first before last");
            $this->assertLessThan($phoneGroupAt, $lastAt, "{$page}: names before phone group");
            // CF-5: sticky mobile footer in both modes.
            $this->assertStringContainsString('sticky bottom-0', $html, "{$page}: footer is not sticky on mobile");
        }

        // Add: fields side by side at sm+ is a grid with both name fields.
        $this->assertMatchesRegularExpression('/sm:grid-cols-2[^"]*"[^>]*>\s*<div>\s*<label for="first_name"/s', $add);
        // Cancel targets are unchanged (CF-1 rule).
        $this->assertStringContainsString('href="'.route('customers.index').'"', $add);
        $this->assertStringContainsString('href="'.route('customers.show', $membership).'"', $edit);
        $this->assertStringContainsString('Add a customer', $add);
        $this->assertStringContainsString('Save changes', $edit);
    }

    public function test_add_carries_phone_help_and_consent_switch_row_with_consequence(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('customers.create'))->assertOk()->getContent();

        // [CF-1] phone help, verbatim.
        $this->assertStringContainsString('US number. We text the confirmation to this number.', $html);
        // CF-3: switch row, shipped label bold, [CF-2] consequence under it.
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*name="consent"[^>]*class="switch/s', $html);
        $this->assertStringContainsString('Agreed to receive text messages', $html);
        $consequenceAt = strpos($html, 'We send one text so they can confirm. No confirmation, no referral link.');
        $this->assertNotFalse($consequenceAt, 'missing [CF-2] consequence line');
        $this->assertLessThan($consequenceAt, strpos($html, 'name="consent"') ?: 0);
    }

    public function test_error_summary_lists_two_invalid_fields_as_anchor_links(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->from(route('customers.create'))->post(route('customers.store'), [
            'first_name' => '',
            'phone' => 'not-a-phone',
        ])->assertSessionHasErrors(['first_name', 'phone']);

        $html = $this->actingAs($owner)->get(route('customers.create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-error-summary', $html, 'the single-source error summary did not render');
        $this->assertMatchesRegularExpression('/<a class="underline" href="#first_name">Enter the customer name.<\/a>/', $html);
        $this->assertMatchesRegularExpression('/<a class="underline" href="#phone">Enter a valid phone number.<\/a>/', $html);
        // Focus moves to the summary (the component ships its focus script).
        $this->assertStringContainsString('data-error-summary', $html);
        $this->assertMatchesRegularExpression('/querySelector\(\'\[data-error-summary\]\'\)/', $html);
    }

    public function test_consent_error_renders_at_the_switch_row(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $bag = new ViewErrorBag;
        $bag->put('default', new MessageBag(['consent' => ['Consent probe error.']]));

        $html = $this->actingAs($owner)->withSession(['errors' => $bag])
            ->get(route('customers.create'))->assertOk()->getContent();

        $this->assertStringContainsString('id="consent-error"', $html, 'consent error is not rendered at the switch row');
        $errorAt = strpos($html, 'id="consent-error"');
        $consequenceAt = strpos($html, 'We send one text so they can confirm.');
        $this->assertNotFalse($consequenceAt);
        $this->assertGreaterThan($consequenceAt, $errorAt, 'consent error must sit at the switch row, after its consequence line');
        // And it is listed in the summary with an anchor to the switch.
        $this->assertMatchesRegularExpression('/<a class="underline" href="#consent">Consent probe error.<\/a>/', $html);
    }

    public function test_edit_identity_header_and_phone_fact_row_with_disclosure(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550402', 'Ana', 'Ray'));
        $membership->forceFill(['sms_consent' => true])->save();

        $html = $this->actingAs($owner)->get(route('customers.edit', $membership))->assertOk()->getContent();

        // CF-4 identity header: monogram initials, name as panel heading, pill, meta line.
        $this->assertStringContainsString('>AR<', $html, 'monogram initials missing');
        $this->assertMatchesRegularExpression('/<h2[^>]*>Ana Ray<\/h2>/', $html, 'customer name is not the panel heading');
        $this->assertStringContainsString('pill pill-success', $html);
        $this->assertStringContainsString('OK to text', $html);
        $this->assertStringContainsString('Joined '.$membership->created_at->format('M j, Y'), $html);
        // Phone fact row + disclosure (CF-3 label), remedies as separate lines.
        $this->assertStringContainsString('+1 (212) 555-0402', $html);
        $this->assertStringContainsString('Why can\'t I change this?', $html);
        $this->assertStringContainsString('Phone numbers can\'t be changed here, because texts and sign-in codes go to this number.', $html);
        $this->assertStringContainsString('If your customer got a new number, ask them to scan your QR code and enter the new number; we connect it to their account automatically.', $html);
        $this->assertStringContainsString('If you typed the number wrong, remove them from your store and add them again with the right number.', $html);
        // The old four-line help paragraph is gone.
        $this->assertStringNotContainsString('class="form-help">Phone numbers can\'t be changed here', $html);
        $this->assertStringNotContainsString('name="phone"', $html);
    }

    public function test_duplicate_phone_renders_cf4_error_at_phone_field_with_open_link(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550403', 'Ana', 'Ray'));

        $response = $this->actingAs($owner)->from(route('customers.create'))->post(route('customers.store'), [
            'first_name' => 'Ana',
            'last_name' => 'Ray',
            'phone' => '(212) 555-0403',
        ]);
        $response->assertSessionHasErrors(['phone' => 'This number is already on Ana Ray\'s customer record.']);
        $this->assertSame(1, \App\Models\Membership::query()->where('business_id', $business->id)->count());

        $html = $this->actingAs($owner)->get(route('customers.create'))->assertOk()->getContent();
        $this->assertStringContainsString('id="phone-error"', $html);
        $this->assertStringContainsString('This number is already on Ana Ray&#039;s customer record.', $html);
        $this->assertStringContainsString('Open their page', $html);
        $this->assertStringContainsString('href="'.route('customers.show', $membership).'"', $html);
        // The summary also links the duplicate error to the phone field.
        $this->assertMatchesRegularExpression('/<a class="underline" href="#phone">This number is already on Ana Ray&#039;s customer record.<\/a>/', $html);
    }
}
