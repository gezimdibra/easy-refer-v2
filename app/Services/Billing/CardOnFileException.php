<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * A card cannot be saved for this business yet (its Square
 * Customer does not exist and could not be provisioned). The
 * Section 9.7 sweeper retries customer creation; until then the
 * card-on-file gate stays closed and the business stays in
 * setup mode (spec Section 9.9).
 */
class CardOnFileException extends RuntimeException {}
