<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Spec Section 4.4: password change needs the current password, applies
 * the Section 8.2 policy, kills other sessions, rotates the remember
 * token, and notifies the owner.
 */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);
    }

    private function sessionRow(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test-agent',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);
    }

    public function test_security_page_lists_sessions(): void
    {
        $user = User::factory()->create();
        $this->sessionRow($user, 'listed-session-id');

        $this->actingAs($user)
            ->get(route('security'))
            ->assertOk()
            ->assertSee('Change password')
            ->assertSee('Active sessions')
            ->assertSee('test-agent');
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('security.password'), [
                'current_password' => 'not the current password',
                'password' => 'a new long phrase 123',
                'password_confirmation' => 'a new long phrase 123',
            ])
            ->assertSessionHasErrors('current_password', null, 'password');

        $this->assertTrue(Hash::check(UserFactory::PASSWORD, $user->fresh()->password));
    }

    public function test_wrong_current_password_error_renders_once_under_its_own_form(): void
    {
        // T1-F2: the error was keyed current_password in the shared
        // default bag, and the two-factor panel on the same page
        // carries a current_password field too, so one submit
        // printed the message under both panels. Each form now has
        // its own error bag.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('security.password'), [
                'current_password' => 'not the current password',
                'password' => 'a new long phrase 123',
                'password_confirmation' => 'a new long phrase 123',
            ])
            ->assertSessionHasErrors('current_password', null, 'password');

        $page = $this->actingAs($user)->get(route('security'))->assertOk();
        $this->assertSame(
            1,
            substr_count($page->getContent(), 'The provided password is incorrect.'),
            'The wrong-current-password message must render exactly once, under the change-password form.'
        );
    }

    public function test_reusing_the_current_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('security.password'), [
                'current_password' => UserFactory::PASSWORD,
                'password' => UserFactory::PASSWORD,
                'password_confirmation' => UserFactory::PASSWORD,
            ])
            ->assertSessionHasErrors('password', null, 'password');
    }

    public function test_successful_change_kills_other_sessions_and_notifies(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->carryCookiesFrom($this->actingAs($user)->get(route('security')));
        $currentSessionId = session()->getId();
        $this->sessionRow($user, $currentSessionId);
        $this->sessionRow($user, 'other-device-session');

        $this->put(route('security.password'), [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'a new long phrase 123',
            'password_confirmation' => 'a new long phrase 123',
        ])->assertSessionHas('status', 'Password updated.');

        $this->assertTrue(Hash::check('a new long phrase 123', $user->fresh()->password));
        $this->assertFalse(DB::table('sessions')->where('id', 'other-device-session')->exists());
        $this->assertTrue(DB::table('sessions')->where('id', $currentSessionId)->exists());
        Notification::assertSentTo($user, PasswordChangedNotification::class);
        $this->assertTrue(AuditLog::query()->where('action', 'owner.password_changed')->where('user_id', $user->id)->exists());
    }

    public function test_sign_out_others_deletes_other_sessions_only(): void
    {
        $user = User::factory()->create();

        $this->carryCookiesFrom($this->actingAs($user)->get(route('security')));
        $currentSessionId = session()->getId();
        $this->sessionRow($user, $currentSessionId);
        $this->sessionRow($user, 'other-device-session');

        $this->post(route('security.sign-out-others'))
            ->assertSessionHas('status', 'Other sessions have been signed out.');

        $this->assertFalse(DB::table('sessions')->where('id', 'other-device-session')->exists());
        $this->assertTrue(DB::table('sessions')->where('id', $currentSessionId)->exists());
        $this->assertAuthenticated();
    }

    public function test_sign_out_everywhere_ends_everything(): void
    {
        $user = User::factory()->create(['remember_token' => 'remember-me-token']);
        $this->sessionRow($user, 'some-session');

        $this->actingAs($user)
            ->post(route('security.sign-out-everywhere'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->remember_token);
        $this->assertFalse(DB::table('sessions')->where('user_id', $user->id)->exists());
    }
}
