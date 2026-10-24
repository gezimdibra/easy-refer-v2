<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * The Square call's outcome is unknown: the request may or may
 * not have been processed (timeout, connection failure mid-call).
 * Callers must leave the local record in `pending` with its
 * events claimed; the pending sweeper resolves the truth from
 * the persisted idempotency key (spec Sections 9.3 and 9.7,
 * EC-33). Nothing may retry with a fresh key after this throws.
 */
class SquareOutcomeUnknown extends RuntimeException {}
