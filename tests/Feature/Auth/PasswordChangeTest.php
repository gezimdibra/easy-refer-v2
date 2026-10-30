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

    public function test_wrong_current_password_error_renders_in_summary_and_once_under_its_own_form(): void
    {
        // T1-F2: the error was keyed current_password in the shared
        // default bag, and the two-factor panel on the same page
        // carries a current_password field too, so one submit
        // printed the message under both panels. Each form now has
        // its own error bag.
        // EMPTY ERROR SUMMARY class fix (2026-10-29): the named
        // "password" bag now also feeds the one page summary, so
        // the message renders exactly twice by design: once in the
        // summary list, once under the change-password form — and
        // never under the two-factor panel.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('security.password'), [
                'current_password' => 'not the current password',
                'password' => 'a new long phrase 123',
                'password_confirmation' => 'a new long phrase 123',
            ])
            ->assertSessionHasErrors('current_password', null, 'password');

        $page = $this->actingAs($user)->get(route('security'))->assertOk();
        $html = $page->getContent();
        $this->assertSame(
            2,
            substr_count($html, 'The provided password is incorrect.'),
            'The wrong-current-password message must render in the summary and once under the change-password form.'
        );
        $this->assertMatchesRegularExpression(
            '/data-error-summary>(?:(?!<\/div>).)*The provided password is incorrect\./s',
            $html,
            'The wrong-current-password message must render inside the error summary.'
        );
    }

    /**
     * The concatenated text of every error-summary region on the
     * page (elements carrying data-error-summary).
     */
    private function summaryRegionText(string $html): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $text = '';
        foreach ((new \DOMXPath($dom))->query('//*[@data-error-summary]') as $node) {
            $text .= ' '.$node->textContent;
        }

        return $text;
    }

    private function fieldErrorText(string $html, string $id): ?string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $node = $dom->getElementById($id.'-error');

        return $node?->textContent;
    }

    public function test_password_error_text_renders_inside_the_summary_region_and_at_the_field(): void
    {
        // The summary TEXT pin (EMPTY ERROR SUMMARY class rule,
        // 2026-10-29): for each failure kind, the message TEXT must
        // render inside the summary region and at its field.
        // Asserting the refusal alone is not sufficient.
        $scenarios = [
            'wrong current password' => [
                'input' => [
                    'current_password' => 'not the current password',
                    'password' => 'a new long phrase 123',
                    'password_confirmation' => 'a new long phrase 123',
                ],
                'message' => 'The provided password is incorrect.',
                'field' => 'current_password',
            ],
            'mismatched confirmation' => [
                'input' => [
                    'current_password' => UserFactory::PASSWORD,
                    'password' => 'a new long phrase 123',
                    'password_confirmation' => 'a different long phrase 456',
                ],
                'message' => 'The password field confirmation does not match.',
                'field' => 'password',
            ],
            'too-short new password' => [
                'input' => [
                    'current_password' => UserFactory::PASSWORD,
                    'password' => 'short123',
                    'password_confirmation' => 'short123',
                ],
                'message' => 'The password field must be at least 12 characters.',
                'field' => 'password',
            ],
        ];

        foreach ($scenarios as $name => $scenario) {
            $user = User::factory()->create();

            $this->actingAs($user)
                ->put(route('security.password'), $scenario['input'])
                ->assertSessionHasErrors(null, 'password');

            $html = $this->actingAs($user)->get(route('security'))->assertOk()->getContent();

            $this->assertStringContainsString(
                $scenario['message'],
                $this->summaryRegionText($html),
                "[{$name}] the message text must render inside the summary region."
            );
            $this->assertStringContainsString(
                $scenario['message'],
                (string) $this->fieldErrorText($html, $scenario['field']),
                "[{$name}] the message text must render at its field."
            );
        }
    }

    public function test_failed_password_change_attempts_are_throttled(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)
                ->put(route('security.password'), [
                    'current_password' => 'not the current password',
                    'password' => 'a new long phrase 123',
                    'password_confirmation' => 'a new long phrase 123',
                ])
                ->assertSessionHasErrors('current_password', null, 'password');
        }

        $this->actingAs($user)
            ->put(route('security.password'), [
                'current_password' => 'not the current password',
                'password' => 'a new long phrase 123',
                'password_confirmation' => 'a new long phrase 123',
            ])
            ->assertStatus(429);
    }

    public function test_guests_cannot_reach_the_password_change_endpoint(): void
    {
        $this->put(route('security.password'), [
            'current_password' => 'whatever current 123',
            'password' => 'a new long phrase 123',
            'password_confirmation' => 'a new long phrase 123',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_password_block_sits_beside_the_two_factor_controls(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('security'))->assertOk()->getContent();

        $twoFactor = strpos($html, 'Two-factor authentication');
        $password = strpos($html, '>Password<');
        $sessions = strpos($html, 'Active sessions');

        $this->assertTrue($twoFactor !== false && $password !== false && $sessions !== false);
        $this->assertTrue(
            $twoFactor < $password && $password < $sessions,
            'The Password block must sit directly after the two-factor block and before Active sessions.'
        );
    }

    public function test_password_change_audit_row_carries_no_password_material(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('security.password'), [
                'current_password' => UserFactory::PASSWORD,
                'password' => 'a new long phrase 123',
                'password_confirmation' => 'a new long phrase 123',
            ])
            ->assertSessionHas('status', 'Password updated.');

        $row = AuditLog::query()->where('action', 'owner.password_changed')->where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame([], $row->metadata ?? []);
        $this->assertSame('127.0.0.1', $row->ip_address);
        $encoded = json_encode($row->toArray());
        $this->assertStringNotContainsString('a new long phrase 123', $encoded);
        $this->assertStringNotContainsString(UserFactory::PASSWORD, $encoded);
        $this->assertStringNotContainsString($user->fresh()->password, $encoded, 'The audit row must never carry the stored hash.');
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
