<?php

namespace App\Services\Claimable;

use App\Models\ClaimablePage;
use App\Models\ClaimReview;
use App\Models\User;
use App\Support\OpaqueToken;
use Illuminate\Support\Facades\DB;

/**
 * The operator side of §4's review paths. Approval creates NO
 * account: it mints a single-use 72-hour hand-off for the
 * requesting identity (the operator delivers the link on the
 * channel the shop supplied). Denial closes the review and frees
 * the page back to claimable; the denied identity is locked out
 * of re-requesting for 30 days (enforced at intake).
 *
 * The contested reversal (§4/P10): on a verified challenge the
 * business is PRESERVED and transferred — the squatter's user is
 * detached and flagged, a new owner user is created for the
 * verified owner, and the claim row is annotated. Billing follows
 * the verified business from the transfer date; any charge that
 * accrued on the squatter's card is the existing refund path's
 * business (proven fraud), never re-billed to the real owner.
 */
class ClaimReviewService
{
    /** @return array{review: ClaimReview, plainHandoffToken: string} */
    public function approve(ClaimReview $review): array
    {
        $plain = OpaqueToken::generate();

        $review->forceFill([
            'status' => 'approved',
            'handoff_token_hash' => OpaqueToken::hash($plain),
            'handoff_expires_at' => now()->addHours((int) config('claimable.review_handoff_hours')),
            'decided_at' => now(),
        ])->save();

        return ['review' => $review, 'plainHandoffToken' => $plain];
    }

    public function deny(ClaimReview $review): void
    {
        DB::transaction(function () use ($review) {
            $review->forceFill(['status' => 'denied', 'decided_at' => now()])->save();

            $page = $review->page;
            if ($page->status === ClaimablePage::STATUS_PENDING_REVIEW) {
                $page->forceFill([
                    'status' => $page->sent_at !== null
                        ? ClaimablePage::STATUS_SENT : ClaimablePage::STATUS_GENERATED,
                ])->save();
            }
        });
    }

    /**
     * §4 contested reversal. $newOwner = ['name','email','phone_e164','password']
     * for the verified owner. The squatter's access ends everywhere
     * (their user is detached from the business and flagged); the
     * business row, its QR code, and any real activity are kept.
     */
    public function reverseContestedClaim(ClaimReview $review, array $newOwner): User
    {
        abort_unless($review->kind === ClaimReview::KIND_CONTESTED, 422);

        return DB::transaction(function () use ($review, $newOwner) {
            $page = $review->page;
            $business = $page->claimedBusiness;
            abort_if($business === null, 422);

            $squatters = User::query()->where('business_id', $business->id)->where('role', 'owner')->get();

            $user = User::create([
                'business_id' => $business->id,
                'role' => 'owner',
                'name' => $newOwner['name'],
                'email' => $newOwner['email'],
                'canonical_email' => $newOwner['canonical_email'] ?? null,
                'phone_e164' => $newOwner['phone_e164'] ?? null,
                'password' => $newOwner['password'],
            ]);

            // The squatter's access ends everywhere: detached
            // from the business (users.business_id is nullable by
            // design) and the detachment is audit-logged, which is
            // the flag the register keeps (§4: full audit retained).
            foreach ($squatters as $squatter) {
                $squatter->forceFill(['business_id' => null])->save();
                \App\Models\AuditLog::write('claim.contested_reversal_squatter_detached', $squatter, [], $business);
            }

            $review->forceFill(['status' => 'reversed', 'decided_at' => now()])->save();
            $page->recordEvent('contested', ['outcome' => 'reversed']);

            return $user;
        });
    }
}
