<?php

namespace Tests\Unit;

use App\Support\UsState;
use Tests\TestCase;

/**
 * The US state normalizer behind the register and profile state
 * fields: codes pass through in any case, full names resolve to
 * their code, and anything outside the list stays unresolvable so
 * the forms can refuse it against the real list.
 */
class UsStateTest extends TestCase
{
    public function test_codes_normalize_in_any_case(): void
    {
        $this->assertSame('NY', UsState::normalize('NY'));
        $this->assertSame('NY', UsState::normalize('ny'));
        $this->assertSame('NY', UsState::normalize(' Ny '));
        $this->assertSame('DC', UsState::normalize('DC'));
    }

    public function test_full_names_normalize_to_the_code(): void
    {
        $this->assertSame('NY', UsState::normalize('New York'));
        $this->assertSame('NY', UsState::normalize('new york'));
        $this->assertSame('NY', UsState::normalize('NEW  YORK'));
        $this->assertSame('CA', UsState::normalize('California'));
        $this->assertSame('DC', UsState::normalize('District of Columbia'));
    }

    public function test_values_outside_the_list_do_not_normalize(): void
    {
        $this->assertNull(UsState::normalize('ZZ'));
        $this->assertNull(UsState::normalize('New Yorkshire'));
        $this->assertNull(UsState::normalize('Ontario'));
        $this->assertNull(UsState::normalize(''));
        $this->assertNull(UsState::normalize(null));
    }
}
