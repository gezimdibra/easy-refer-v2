<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side Cloudflare Turnstile verification for the public
 * submits that need it (spec Sections 3, 4): enrollment, friend
 * claim, and the marketing lead forms. Active only when keys are
 * configured; an unconfigured environment skips the challenge (the
 * Phase 1 login pattern), and a verification failure or outage
 * fails closed.
 */
class TurnstileVerifier
{
    public function configured(): bool
    {
        return ! empty(config('services.turnstile.site_key'))
            && ! empty(config('services.turnstile.secret_key'));
    }

    public function passes(?string $token, string $ipAddress): bool
    {
        if (! $this->configured()) {
            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => (string) config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ipAddress,
                ]);

            return $response->successful() && $response->json('success') === true;
        } catch (\Throwable $e) {
            Log::warning('Turnstile verification request failed.', ['exception' => $e::class]);

            return false;
        }
    }
}
