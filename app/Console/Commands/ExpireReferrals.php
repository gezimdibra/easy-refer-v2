<?php

namespace App\Console\Commands;

use App\Models\Referral;
use Illuminate\Console\Command;

/**
 * The referral expiry sweeper (spec Section 3.1): pending
 * referrals past their expires_at flip to expired. Reads also
 * apply expiry lazily; this keeps the stored state honest for the
 * dashboard and the review queue.
 */
class ExpireReferrals extends Command
{
    protected $signature = 'referrals:expire';

    protected $description = 'Flip pending referrals past their expiry to expired';

    public function handle(): int
    {
        $flipped = 0;

        Referral::query()
            ->where('state', Referral::STATE_PENDING)
            ->where('expires_at', '<=', now())
            ->chunkById(200, function ($referrals) use (&$flipped) {
                foreach ($referrals as $referral) {
                    if ($referral->applyExpiry()) {
                        $flipped++;
                    }
                }
            });

        $this->info("Expired {$flipped} referral(s).");

        return self::SUCCESS;
    }
}
