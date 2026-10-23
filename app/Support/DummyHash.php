<?php

namespace App\Support;

/**
 * A precomputed Argon2id hash of a random throwaway string, at the
 * production parameters (config/hashing.php). Authentication paths run
 * password_verify against it whenever there is no real account hash to
 * check, so unknown identifiers cost the same work as real ones and the
 * response timing does not reveal whether an account exists (spec
 * Section 8.3). Precomputed so a cold worker never pays a hash-make on
 * top of the verify, which would itself be a timing tell. The plaintext
 * is unknown and irrelevant; this is not a credential.
 */
final class DummyHash
{
    private const HASH = '$argon2id$v=19$m=19456,t=2,p=1$dmVycGJlWW1ValdVdlhlZg$KWjNshXwaiKZRnjTT/rRjeGWKbE77IG/uk8sJMKCxy4';

    public static function value(): string
    {
        return self::HASH;
    }
}
