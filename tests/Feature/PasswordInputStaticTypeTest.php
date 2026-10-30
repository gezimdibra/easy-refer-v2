<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * U3 (launch-readiness hardening): the shared password component
 * set the input type only through the Alpine binding, so before
 * (or without) JavaScript the field was not a password field at
 * all in the served markup. The component now carries the static
 * type="password" attribute alongside the binding; Alpine still
 * drives the show and hide toggle from that same starting state.
 * The pin asserts on the raw served HTML, never on Alpine state.
 */
class PasswordInputStaticTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_password_field_is_a_password_field_in_the_served_html(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="password"[^>]*type="password"/',
            $html,
            'the served login markup carries no static type="password" on the password field'
        );
        // The Alpine toggle binding stays: showing the password
        // is still the toggle button's job.
        $this->assertStringContainsString(':type="show ? \'text\' : \'password\'"', $html);
    }

    public function test_the_register_password_field_is_a_password_field_in_the_served_html(): void
    {
        $response = $this->get(route('register'))->assertOk();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="password"[^>]*type="password"/',
            $response->getContent(),
            'the served register markup carries no static type="password" on the password field'
        );
    }
}
