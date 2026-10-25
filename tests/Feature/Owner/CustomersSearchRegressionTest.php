<?php

namespace Tests\Feature\Owner;

use Tests\Support\World;
use Tests\TestCase;

/**
 * DG-3-family regression for round-2 audit F01: customers
 * search returned the branded HTTP 500 for ANY non-empty
 * query on staging. Root cause was engine divergence: the
 * search clause's ESCAPE literal is a backslash, which
 * MariaDB cannot parse inside a string literal while SQLite
 * (the suite) reads it as plain text, so no gate ever saw
 * the failure; the gates also never sent a non-empty query.
 * These checks pin the contract on every branch a gate must
 * exercise: a name match, a phone-fragment match, and a
 * no-match rendering the CP-11 search empty state, all 200.
 */
class CustomersSearchRegressionTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_search_by_existing_name_answers_200_with_the_match(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::membership($business, World::customer('+12125550211', 'Marisol', 'Vega'));
        World::membership($business, World::customer('+12125550212', 'Peter', 'Hale'));

        $this->actingAs($owner)->get(route('customers.index', ['q' => 'Marisol']))
            ->assertOk()
            ->assertSee('Marisol')
            ->assertDontSee('Peter');
    }

    public function test_search_by_phone_fragment_answers_200_with_the_match(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::membership($business, World::customer('+12125550211', 'Marisol', 'Vega'));
        World::membership($business, World::customer('+12125550212', 'Peter', 'Hale'));

        $this->actingAs($owner)->get(route('customers.index', ['q' => '0212']))
            ->assertOk()
            ->assertSee('Peter')
            ->assertDontSee('Marisol');
    }

    public function test_search_with_no_match_answers_200_with_the_cp11_empty_state(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        World::membership($business, World::customer('+12125550211', 'Marisol', 'Vega'));

        $this->actingAs($owner)->get(route('customers.index', ['q' => 'zzz-no-match']))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            // CP-11 search variant: names what was searched and
            // suggests the recovery, with the next action offered.
            ->assertSee('No customers match that search.')
            ->assertSee('Check the spelling, or add them as a new customer.')
            ->assertDontSee('Marisol');
    }
}
