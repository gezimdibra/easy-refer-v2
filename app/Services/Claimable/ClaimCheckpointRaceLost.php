<?php

namespace App\Services\Claimable;

/**
 * Thrown inside the signup checkpoint transaction when the claim
 * flip's in-transaction re-check (review item 1a) finds the page
 * no longer claimable: a competing checkpoint claimed it, or a
 * removal committed, after this session's pre-check read it as
 * claimable. Carries the status OBSERVED under the row lock so
 * the caller can record the §9.2 blocked event for the removal
 * case after the transaction has rolled back (an event written
 * inside the losing transaction would roll back with it). The
 * caller converts this into the designed refusal (G1-C37,
 * nothing created).
 */
class ClaimCheckpointRaceLost extends \RuntimeException
{
    public function __construct(
        public readonly int $pageId,
        public readonly ?string $observedStatus,
    ) {
        parent::__construct('claim_checkpoint_race_lost');
    }
}
