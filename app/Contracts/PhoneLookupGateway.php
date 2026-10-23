<?php

namespace App\Contracts;

/**
 * Phone line intelligence (spec Section 3.1 EC-16, twilio-blueprint
 * Section 2): SIM-swap and line-tenure signals, used only as a
 * step-up risk signal inside the number-link cooling window. A high
 * signal holds for review; it never hard-blocks.
 */
interface PhoneLookupGateway
{
    /**
     * Result keys: sim_changed_recently (bool), line_type (nullable
     * string), carrier (nullable string). On provider failure every
     * signal is neutral (false / null): an outage never punishes a
     * customer.
     *
     * @return array{sim_changed_recently: bool, line_type: ?string, carrier: ?string}
     */
    public function lookup(string $phoneE164): array;
}
