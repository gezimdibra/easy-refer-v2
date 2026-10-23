<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\RememberToken;
use App\Models\User;
use App\Services\Auth\RememberMeService;
use Database\Seeders\TwoBusinessFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Remember-me token semantics (spec Section 8.4): rotation on every
 * use, expiry, theft response on a superseded validator, and scoped
 * revocation.
 */
class RememberMeServiceTest extends TestCase
{
    use RefreshDatabase;

    private RememberMeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TwoBusinessFixtureSeeder::class);
        $this->service = app(RememberMeService::class);
    }

    private function ownerA(): User
    {
        return User::where('email', TwoBusinessFixtureSeeder::A_EMAIL)->firstOrFail();
    }

    public function test_issue_sets_a_scoped_cookie_and_stores_only_the_hash(): void
    {
        $cookie = $this->service->issue($this->ownerA());

        $this->assertSame(RememberMeService::COOKIE_NAME, $cookie->getName());
        $this->assertSame((string) config('session.path'), $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());

        $row = RememberToken::firstOrFail();
        [$selector, $validator] = explode('.', (string) $cookie->getValue(), 2);
        $this->assertSame($selector, $row->selector);
        $this->assertSame(hash('sha256', $validator), $row->validator_hash);
    }

    public function test_consume_rotates_the_validator_and_returns_the_user(): void
    {
        $cookie = $this->service->issue($this->ownerA());
        $hashBefore = RememberToken::firstOrFail()->validator_hash;

        $result = $this->service->consume($cookie->getValue());

        $this->assertNotNull($result);
        $this->assertSame($this->ownerA()->id, $result['user']->id);
        $this->assertNotSame($hashBefore, RememberToken::firstOrFail()->validator_hash);
        $this->assertNotNull(RememberToken::firstOrFail()->last_used_at);
        $this->assertNotSame($cookie->getValue(), $result['cookie']->getValue());
    }

    public function test_consume_rejects_null_and_malformed_values(): void
    {
        $this->service->issue($this->ownerA());

        $this->assertNull($this->service->consume(null));
        $this->assertNull($this->service->consume('no-separator'));
        $this->assertNull($this->service->consume('unknownselector1.whatever'));
        $this->assertSame(0, AuditLog::where('action', 'owner.remember_token_theft')->count());
    }

    public function test_consume_deletes_and_rejects_an_expired_token(): void
    {
        $cookie = $this->service->issue($this->ownerA());
        RememberToken::firstOrFail()->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertNull($this->service->consume($cookie->getValue()));
        $this->assertSame(0, RememberToken::count());
    }

    public function test_a_superseded_validator_wipes_every_token_and_is_audited(): void
    {
        $first = $this->service->issue($this->ownerA());
        $this->service->issue($this->ownerA());
        $this->assertSame(2, RememberToken::count());

        $rotated = $this->service->consume($first->getValue());
        $this->assertNotNull($rotated);

        // The original value is now superseded: theft response.
        $this->assertNull($this->service->consume($first->getValue()));
        $this->assertSame(0, RememberToken::count());
        $this->assertSame(1, AuditLog::where('action', 'owner.remember_token_theft')->count());

        // The rotated value died with the wipe as well.
        $this->assertNull($this->service->consume($rotated['cookie']->getValue()));
    }

    public function test_revoke_value_and_revoke_all_are_scoped(): void
    {
        $mine = $this->service->issue($this->ownerA());
        $this->service->issue($this->ownerA());

        $other = User::where('email', TwoBusinessFixtureSeeder::B_EMAIL)->firstOrFail();
        $this->service->issue($other);

        $this->service->revokeValue($mine->getValue());
        $this->assertSame(2, RememberToken::count());

        $this->service->revokeValue(null);
        $this->service->revokeValue('malformed');
        $this->assertSame(2, RememberToken::count());

        $this->service->revokeAll($this->ownerA());
        $this->assertSame(1, RememberToken::count());
        $this->assertSame($other->id, RememberToken::firstOrFail()->user_id);
    }
}
