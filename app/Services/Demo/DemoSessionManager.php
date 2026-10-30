<?php

namespace App\Services\Demo;

use App\Models\DemoSession;
use App\Support\OpaqueToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The demo session store (G6 demo Phase 1, Section 11): a
 * separate cookie and record from the customer flow session,
 * with a 15-minute FIXED expiry from creation. The expiry
 * never slides: put() writes state without touching
 * expires_at, so a slow session dies on the same minute
 * however active it is, and the phone-fate promise (the typed
 * number is deleted at expiry) names an exact moment.
 *
 * Expired records are deleted on sight at every read, and
 * their derived rows are torn down BEFORE the record dies
 * (A-18 Finding 2: deleting the record first would lose the
 * IDs the teardown needs). The minutely expiry sweep
 * (demo:sweep-expired) does the same for sessions nobody
 * returns to, so deletion happens within a few minutes of
 * expiry whether or not there is a next visit; the nightly
 * reseed is only a backstop.
 */
class DemoSessionManager
{
    public function cookieName(): string
    {
        return (string) config('demo.cookie');
    }

    public function ttlMinutes(): int
    {
        return (int) config('demo.session_ttl_minutes', 15);
    }

    /**
     * Start a fresh demo session and return [record, cookie token].
     *
     * @param  array<string, mixed>  $data
     * @return array{0: DemoSession, 1: string}
     */
    public function start(array $data = []): array
    {
        $token = OpaqueToken::generate();

        $record = DemoSession::create([
            'id' => OpaqueToken::hash($token),
            'data' => $data,
            'expires_at' => Carbon::now()->addMinutes($this->ttlMinutes()),
        ]);

        return [$record, $token];
    }

    /**
     * The live demo session behind the request cookie, or null.
     * An expired record is deleted on sight and reads as no
     * session; the controller distinguishes "had a session that
     * died" (the timeout screen) from "never had one" (the
     * start card) through hadCookie().
     */
    public function fromRequest(Request $request): ?DemoSession
    {
        $token = $request->cookie($this->cookieName());

        if (! is_string($token) || $token === '') {
            return null;
        }

        $record = DemoSession::query()->find(OpaqueToken::hash($token));

        if ($record === null) {
            return null;
        }

        if ($record->expires_at->isPast()) {
            $this->expire($record);

            return null;
        }

        return $record;
    }

    /**
     * Whether the request carried a demo cookie at all, live
     * record or not.
     */
    public function hadCookie(Request $request): bool
    {
        $token = $request->cookie($this->cookieName());

        return is_string($token) && $token !== '';
    }

    public function get(DemoSession $session, string $key, mixed $default = null): mixed
    {
        return ($session->data ?? [])[$key] ?? $default;
    }

    /**
     * Write values into the session data. The expiry NEVER
     * slides (Section 11.1): expires_at is untouched here.
     *
     * @param  array<string, mixed>  $values
     */
    public function put(DemoSession $session, array $values): void
    {
        $session->data = array_merge($session->data ?? [], $values);
        $session->save();
    }

    public function forget(DemoSession $session, string $key): void
    {
        $data = $session->data ?? [];
        unset($data[$key]);
        $session->data = $data;
        $session->save();
    }

    public function destroy(DemoSession $session): void
    {
        $session->delete();
    }

    /**
     * End one expired session for good: its derived rows are
     * torn down first (DemoService::teardownSessionRows reads
     * the claim, membership and customer IDs out of this
     * record), then the record itself, with the typed phone
     * in it, is deleted.
     */
    public function expire(DemoSession $session): void
    {
        app(DemoService::class)->teardownSessionRows($session);
        $session->delete();
    }

    /**
     * Delete every expired session record AND tear down the
     * rows each session derived (the sweep leg of the
     * phone-fate rule; returns how many sessions were swept).
     * Run on a minutely cadence by the demo:sweep-expired
     * command, and again as a backstop inside the reseed.
     */
    public function sweepExpired(): int
    {
        $expired = DemoSession::query()->where('expires_at', '<=', now())->get();

        foreach ($expired as $record) {
            $this->expire($record);
        }

        return $expired->count();
    }

    public function cookie(string $token): Cookie
    {
        return Cookie::create(
            $this->cookieName(),
            $token,
            now()->addMinutes($this->ttlMinutes())->timestamp,
            (string) config('session.path'),
            null,
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        );
    }

    public function forgetCookie(): Cookie
    {
        return Cookie::create(
            $this->cookieName(),
            '',
            now()->subYears(5)->timestamp,
            (string) config('session.path'),
            null,
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        );
    }
}
