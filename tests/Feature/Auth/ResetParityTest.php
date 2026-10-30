<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * AU-6/AU-7 (R5 pack A4 pick B): the reset form presents the
 * account identity instead of re-asking for the email, renders
 * the shared password-rules component, and an expired or used
 * token lands on the designed expired state rather than a bare
 * form error.
 */
class ResetParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reset_form_presents_identity_and_shared_rules(): void
    {
        $user = User::factory()->create(['name' => 'Marcus Webb']);
        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Marcus Webb')
            ->assertSee($user->email)
            ->assertSee('Not you?')
            ->assertSee('Use at least 12 characters.')
            ->assertSee('Avoid passwords you have used anywhere else.')
            ->assertSee('Resetting signs you out everywhere else, too.')
            ->assertSee('readonly', false);
    }

    public function test_an_invalid_token_lands_on_the_expired_state(): void
    {
        $user = User::factory()->create();
        Password::broker()->createToken($user);

        // The failed POST redirects back with the token error in
        // the shared bag; the re-rendered page (the browser's next
        // request, session carried) is the designed state.
        $this->post(route('password.store'), [
            'token' => 'not-the-token',
            'email' => $user->email,
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
        ])->assertSessionHasErrors('email');

        $this->get(route('password.reset', ['token' => 'not-the-token', 'email' => $user->email]))
            ->assertOk()
            ->assertSee('This link has expired')
            ->assertSee('Reset links work for 60 minutes, and only once.')
            ->assertSee('Email me a new link');
    }
}
