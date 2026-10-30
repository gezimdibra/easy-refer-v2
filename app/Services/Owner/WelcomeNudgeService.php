<?php

namespace App\Services\Owner;

use App\Mail\WelcomeMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The unverified welcome nudge (owner amendment, 2026-10-29;
 * copy-inventory Amendment 59). The owner, on Finding 7: the
 * signup moment sends the verification email only; the welcome
 * mail waits "till they use it for a day at least and havent
 * verified yet".
 *
 * Eligibility, ALL of: the account is at least 24 hours old
 * (users.created_at), the email is still unverified, and the
 * nudge has never been sent (users.welcome_nudge_sent_at is
 * null). Verified inside day one, the nudge never exists.
 * Once ever: no drip; later reminding is the in-product
 * banner/pill system's job.
 *
 * The once-only rule is structural. The service claims the
 * send with ONE conditional update (stamp the marker where it
 * is still null and the address is still unverified); only a
 * run that actually changed the row may send, so overlapping
 * scheduler runs cannot both send. The stamp-then-send order
 * follows the column-stamp discipline of the reward/campaign
 * sends, with the AuditLog row written after the send as the
 * observability record (the forecast/dunning pattern). If the
 * send itself throws, the claim is released (marker back to
 * null) and the failure is logged, so a dead mail transport
 * does not silently spend the account's one nudge.
 *
 * Deferrals (never stamped, never sent, re-checked next run):
 * a business that is closed, disabled, or dunning-paused, or
 * that does not exist. In those states the mail's "steps left
 * before your code works" count cannot be made true by the
 * setup steps alone (a paused or disabled code does not start
 * working when the last setup step lands), so the nudge waits
 * for a state its copy is true in; a closed business simply
 * never leaves that state and never gets the mail.
 */
class WelcomeNudgeService
{
    public const MINIMUM_AGE_HOURS = 24;

    /** @return array{sent: int, skipped: int} */
    public function run(): array
    {
        $summary = ['sent' => 0, 'skipped' => 0];
        $cutoff = now()->subHours(self::MINIMUM_AGE_HOURS);

        User::query()
            ->whereNull('email_verified_at')
            ->whereNull('welcome_nudge_sent_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->each(function (User $user) use (&$summary): void {
                if (! $this->businessCanBeNudged($user)) {
                    $summary['skipped']++;

                    return;
                }

                // The structural claim: exactly one runner can
                // turn this row's marker from null to a time.
                $claimed = User::query()
                    ->whereKey($user->id)
                    ->whereNull('email_verified_at')
                    ->whereNull('welcome_nudge_sent_at')
                    ->update(['welcome_nudge_sent_at' => now()]);

                if ($claimed !== 1) {
                    $summary['skipped']++;

                    return;
                }

                try {
                    // Mail::send, never queue: WelcomeMail is not
                    // a ShouldQueue mailable, so it renders NOW,
                    // at send time; its subject, title, count, and
                    // the fresh signed verification link are all
                    // minted from the facts at this moment.
                    Mail::to($user->email)->send(new WelcomeMail($user->fresh() ?? $user));
                } catch (\Throwable) {
                    User::query()
                        ->whereKey($user->id)
                        ->update(['welcome_nudge_sent_at' => null]);
                    Log::error('Welcome nudge could not be sent; claim released for the next run.', [
                        'user_id' => $user->id,
                    ]);
                    $summary['skipped']++;

                    return;
                }

                AuditLog::write('owner.welcome_nudge_sent', null, [
                    'account_age_hours' => self::MINIMUM_AGE_HOURS,
                ], $user->business, $user);
                $summary['sent']++;
            });

        return $summary;
    }

    private function businessCanBeNudged(User $user): bool
    {
        $business = $user->business;

        if ($business === null || $business->isClosed() || $business->closed_at !== null) {
            return false;
        }

        if (! $business->isActive()) {
            return false;
        }

        return ! ($business->billing_status === 'past_due' && $business->dunningPausedAt());
    }
}
