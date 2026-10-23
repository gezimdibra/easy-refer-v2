<?php

namespace App\Services\Flow;

use App\Models\FlowSession;
use App\Support\OpaqueToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The customer flow session (spec Sections 3 and 8.4): a separate
 * 30-minute cookie with the same flags as the owner session,
 * carrying verified state server-side only. The cookie holds an
 * opaque token; the record it keys holds the flow's state. A
 * business's flow never leaks into another business's flow: the
 * record is bound to the business it started at, and arriving from
 * a different business's code starts a fresh one (Step 2).
 */
class FlowSessionManager
{
    public function cookieName(): string
    {
        return (string) config('security.flow.cookie');
    }

    public function ttlMinutes(): int
    {
        return (int) config('security.flow.ttl_minutes');
    }

    /**
     * Start a fresh flow session and return [record, cookie token].
     *
     * @param  array<string, mixed>  $data
     * @return array{0: FlowSession, 1: string}
     */
    public function start(array $data = []): array
    {
        $token = OpaqueToken::generate();

        $record = FlowSession::create([
            'id' => OpaqueToken::hash($token),
            'data' => $data,
            'expires_at' => Carbon::now()->addMinutes($this->ttlMinutes()),
        ]);

        return [$record, $token];
    }

    /**
     * The live flow session behind the request cookie, or null.
     * Expired records are deleted on sight (expiry applied at every
     * read, Section 7.2).
     */
    public function fromRequest(Request $request): ?FlowSession
    {
        $token = $request->cookie($this->cookieName());

        if (! is_string($token) || $token === '') {
            return null;
        }

        $record = FlowSession::query()->find(OpaqueToken::hash($token));

        if ($record === null) {
            return null;
        }

        if ($record->expires_at->isPast()) {
            $record->delete();

            return null;
        }

        return $record;
    }

    /**
     * Read one value from the flow session data.
     */
    public function get(FlowSession $session, string $key, mixed $default = null): mixed
    {
        return ($session->data ?? [])[$key] ?? $default;
    }

    /**
     * Write values into the flow session data. Sliding the expiry is
     * the caller's choice: the flow timeout is inactivity-based
     * (design R2), so verified progress refreshes it.
     *
     * @param  array<string, mixed>  $values
     */
    public function put(FlowSession $session, array $values, bool $slideExpiry = true): void
    {
        $session->data = array_merge($session->data ?? [], $values);

        if ($slideExpiry) {
            $session->expires_at = Carbon::now()->addMinutes($this->ttlMinutes());
        }

        $session->save();
    }

    public function forget(FlowSession $session, string $key): void
    {
        $data = $session->data ?? [];
        unset($data[$key]);
        $session->data = $data;
        $session->save();
    }

    public function destroy(FlowSession $session): void
    {
        $session->delete();
    }

    /**
     * The cookie that carries a flow token, with the owner session's
     * flags (spec Section 8.4): Secure, HttpOnly, SameSite=Lax, and
     * the mount path from the one config point.
     */
    /**
     * Rotate the flow token at the verification boundary: the
     * record (including the fresh verified marker) moves to a new
     * token and the old one dies, so a flow token planted before
     * verification cannot be replayed after it (security verifier
     * m-4, fixation half).
     *
     * @return array{0: FlowSession, 1: string}
     */
    public function rotate(FlowSession $flow): array
    {
        $data = $flow->data ?? [];
        $flow->delete();

        return $this->start($data);
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

    /**
     * The expired cookie that clears a flow session client-side.
     */
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
