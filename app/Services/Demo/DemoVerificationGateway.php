<?php

namespace App\Services\Demo;

use App\Contracts\PhoneVerificationGateway;

/**
 * The demo verification gateway (G6 demo Phase 1, Section 4.2):
 * the ONLY verification gateway a demo session can resolve. It
 * implements the same provider contract as the Twilio gateway,
 * so the demo's verification behaviour is the real behaviour in
 * kind (per-session generated code, counted attempts, resend
 * invalidating the old code), but it generates and checks codes
 * internally: no SMS exists anywhere in the demo path, and no
 * real gateway is ever called. The code is generated per
 * session server-side, never a fixed value; the demo controller
 * reads it back through codeFor() to render the on-screen code
 * card, which is the demo's disclosed substitute for the text.
 *
 * State lives on the instance; the container binds it scoped,
 * so one request's pending codes never leak into another's.
 * A non-demo request cannot select this gateway: it is bound
 * under its own class name and referenced only by the demo
 * controller.
 */
class DemoVerificationGateway implements PhoneVerificationGateway
{
    /**
     * Pending codes by phone: the code, and the checks spent.
     *
     * @var array<string, array{code: string, checks: int}>
     */
    private array $pending = [];

    public function start(string $phoneE164, string $channel = 'sms'): array
    {
        $length = (int) config('demo.code.length', 6);
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        // A fresh start replaces any pending code for the
        // number: the old code dies here, exactly as a real
        // resend kills the previous code.
        $this->pending[$phoneE164] = ['code' => $code, 'checks' => 0];

        return ['outcome' => 'started', 'provider_status' => 'pending'];
    }

    public function check(string $phoneE164, string $code): array
    {
        $pending = $this->pending[$phoneE164] ?? null;

        if ($pending === null) {
            return ['outcome' => 'denied', 'provider_status' => 'canceled'];
        }

        $this->pending[$phoneE164]['checks']++;

        if (hash_equals($pending['code'], $code)) {
            unset($this->pending[$phoneE164]);

            return ['outcome' => 'approved', 'provider_status' => 'approved'];
        }

        return ['outcome' => 'pending', 'provider_status' => 'pending'];
    }

    /**
     * Rehydrate a pending verification minted by an earlier
     * request (the gateway's store is per-request; the demo
     * session record is the durable store, and the controller
     * restores from it before checking). The code and the
     * spent checks return exactly as they were left.
     */
    public function restore(string $phoneE164, string $code, int $checks): void
    {
        $this->pending[$phoneE164] = ['code' => $code, 'checks' => $checks];
    }

    /**
     * The code the current start generated for a number, for
     * the demo's on-screen code card. Null when no verification
     * is pending for the number.
     */
    public function codeFor(string $phoneE164): ?string
    {
        return $this->pending[$phoneE164]['code'] ?? null;
    }

    /**
     * Checks spent against the pending code for a number.
     */
    public function checksFor(string $phoneE164): int
    {
        return $this->pending[$phoneE164]['checks'] ?? 0;
    }
}
