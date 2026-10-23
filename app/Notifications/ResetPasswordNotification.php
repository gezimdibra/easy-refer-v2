<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordBase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The password reset email (spec Sections 4.3 and 8.3), queued.
 *
 * Queueing is a security property here, not only a performance one
 * (finding L-3): when the mail was sent synchronously, the response
 * for an existing account also paid the mailer latency, so the two
 * reset paths sat in measurably different timing classes. With the
 * send queued, both paths do the same work in the request (token
 * generation plus one Argon2id-class verify) and answer identically.
 */
class ResetPasswordNotification extends ResetPasswordBase implements ShouldQueue
{
    use Queueable;
}
