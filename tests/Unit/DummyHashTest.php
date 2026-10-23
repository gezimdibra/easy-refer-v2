<?php

namespace Tests\Unit;

use App\Support\DummyHash;
use PHPUnit\Framework\TestCase;

/**
 * The dummy hash is a timing device (spec Section 8.3): a real Argon2id
 * hash at production parameters whose plaintext is unknown, so any
 * password verified against it costs full work and fails.
 */
class DummyHashTest extends TestCase
{
    public function test_it_is_an_argon2id_hash_at_production_parameters(): void
    {
        $hash = DummyHash::value();

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertStringContainsString('m=19456,t=2,p=1', $hash);
        $this->assertFalse(password_verify('any-password', $hash));
    }
}
