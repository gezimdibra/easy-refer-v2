<?php

namespace App\Models;

use App\Support\OpaqueToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A business QR code (spec Section 7.2). The raw token exists only in
 * the printed code and the customer's link; the row stores its hash.
 *
 * QRK-05 (Train 3): a code also carries its store placement
 * (migration 000029). One business holds one ACTIVE code per
 * placement; each placement is its own token resolving to the same
 * join flow, so scans attribute to the spot that produced them.
 * Rows from before placements carry 'counter' (the migration
 * backfill) or null (created outside the issuance path) and keep
 * resolving through the fallback chain in Business::activeQrCode().
 */
class QrCode extends Model
{
    public const UPDATED_AT = null;

    /** The standing placements, in panel order (QRK-05). */
    public const PLACEMENTS = ['counter', 'window', 'table', 'receipt'];

    /**
     * Placement -> the printed format's name (Amendment 31
     * [ON-SPOT-2]): a code with no owner-set name displays as
     * the format it prints, the same words the Print buttons
     * use. The name describes the printed piece, never a
     * location the app assumed.
     *
     * @var array<string, string>
     */
    public const FORMAT_NAMES = [
        'counter' => 'Counter poster',
        'window' => 'Window poster',
        'table' => 'Table tent',
        'receipt' => 'Receipt card',
    ];

    /** The format (default) display name for one placement. */
    public static function formatNameForPlacement(?string $placement): string
    {
        return self::FORMAT_NAMES[$placement ?? 'counter'] ?? self::FORMAT_NAMES['counter'];
    }

    /**
     * The name this code displays under (Amendment 31
     * [ON-SPOT-2]/[ON-SPOT-3]): the owner-set label when one
     * is set, else the format name. The single source of
     * truth for every surface that names a code; scan
     * counting never reads it (it keys on the placement).
     */
    public function displayName(): string
    {
        $label = trim((string) $this->label);

        return $label !== '' ? $label : self::formatNameForPlacement($this->placement);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'token_hash',
        'token_encrypted',
        'state',
        'label',
        'placement',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
            // Sealed copy for re-presentation on the owner's QR page
            // and posters (migration 000018); lookup stays by hash.
            'token_encrypted' => 'encrypted',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isActive(): bool
    {
        return $this->state === 'active';
    }

    /**
     * Issue a QR code and return the raw token, which the caller
     * surfaces exactly once (the printable code). The token itself
     * is never stored or logged. Issuance names a placement
     * (QRK-05); the default is 'counter', the launch placement every
     * pre-placement caller meant.
     */
    public static function issueFor(Business $business, ?string $label = null, string $placement = 'counter'): string
    {
        $token = OpaqueToken::generate();

        static::create([
            'business_id' => $business->id,
            'token_hash' => OpaqueToken::hash($token),
            'token_encrypted' => $token,
            'state' => 'active',
            'label' => $label,
            'placement' => $placement,
        ]);

        return $token;
    }

    /** The business's active code for one placement, if any. */
    public static function activeForPlacement(Business $business, string $placement): ?static
    {
        return static::query()
            ->where('business_id', $business->id)
            ->where('placement', $placement)
            ->where('state', 'active')
            ->latest('id')
            ->first();
    }

    /**
     * The active code for every standing placement, keyed by
     * placement, issuing any that are missing (QRK-05). A code
     * whose sealed copy is missing (issued before migration
     * 000018) cannot be re-presented, so it is revoked and
     * reissued, mirroring the QR page's standing liveCode rule.
     *
     * @return array<string, static>
     */
    public static function ensurePlacements(Business $business): array
    {
        // M2 (Train-3 review, hardening): the whole pass — the
        // self-heal sweep and the missing-placement issuance —
        // runs inside one transaction under row locks, the FB-01
        // pattern the replace paths below already use. Without
        // it, two simultaneous first-ever QR-page loads could
        // each find a placement missing and each issue a code
        // for it, leaving a duplicate active row. The lock
        // anchor is the business row itself (the SettlementService
        // shape): the qr_codes rows that would need locking do
        // not exist yet on a first load, so locking them alone
        // serializes nothing. Holding the business lock from the
        // first read to commit makes the second load wait, and
        // its placement reads are locking reads (lockForUpdate),
        // so they see the first load's committed codes and issue
        // nothing. The replace paths take qr_codes locks only
        // and never the business lock, so no lock-order cycle
        // exists between the paths.
        return DB::transaction(function () use ($business) {
            $locked = Business::query()
                ->whereKey($business->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Legacy self-heal first, generalized from the QR page's
            // standing liveCode rule: an active code with no sealed
            // copy can never be re-presented, whichever placement it
            // claims (or none — pre-placement rows carry null), so it
            // is revoked before the per-placement pass reissues its
            // spot. Without this pass a null-placement legacy row
            // would linger active forever: no placement lookup ever
            // finds it. (Named by RiskAndReleaseTest's
            // test_qr_page_image_and_legacy_code_self_heal.)
            // [ON-SPOT-3]: a self-heal reissue inherits the revoked
            // code's owner-set name, so healing never erases a
            // rename. Labels are captured per placement before the
            // sweep revokes anything.
            $healedLabels = [];
            static::query()
                ->where('business_id', $locked->id)
                ->where('state', 'active')
                ->whereNull('token_encrypted')
                ->lockForUpdate()
                ->get()
                ->each(function (QrCode $code) use (&$healedLabels) {
                    if ($code->placement !== null && $code->label !== null) {
                        $healedLabels[$code->placement] = $code->label;
                    }
                    $code->forceFill(['state' => 'revoked', 'revoked_at' => now()])->save();
                });

            $codes = [];

            foreach (self::PLACEMENTS as $placement) {
                $code = self::activeForPlacementLocked($locked, $placement);

                if ($code === null) {
                    self::issueFor($locked, $healedLabels[$placement] ?? null, $placement);
                    $code = self::activeForPlacementLocked($locked, $placement);
                }

                $codes[$placement] = $code;
            }

            return $codes;
        });
    }

    /**
     * The active code for one placement, read as a locking read
     * inside the caller's transaction (M2): unlike the plain
     * activeForPlacement read, this sees rows committed by a
     * transaction that held the business lock before this one,
     * so an ensurePlacements pass that waited on that lock
     * never re-issues a placement the winner already covered.
     */
    private static function activeForPlacementLocked(Business $business, string $placement): ?static
    {
        return static::query()
            ->where('business_id', $business->id)
            ->where('placement', $placement)
            ->where('state', 'active')
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    /**
     * Replace one placement's active code (QRK-05): revoke the
     * current row and issue a fresh one inside one transaction
     * under a row lock, the FB-01 pattern, so two racing replaces
     * can never leave two active codes for the placement.
     */
    public static function replacePlacement(Business $business, string $placement): static
    {
        return DB::transaction(function () use ($business, $placement) {
            $current = static::query()
                ->where('business_id', $business->id)
                ->where('placement', $placement)
                ->where('state', 'active')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            // [ON-SPOT-3]: the replacement inherits the current
            // code's owner-set name.
            $label = $current?->label;

            if ($current !== null) {
                $current->forceFill(['state' => 'revoked', 'revoked_at' => now()])->save();
            }

            self::issueFor($business, $label, $placement);

            return self::activeForPlacement($business, $placement);
        });
    }

    /**
     * Replace EVERY active code at once (QRK-07's "Replace all
     * codes" danger action, REF-POSTER-03c): all actives revoked
     * and one fresh code per standing placement issued, inside
     * one transaction under row locks (the FB-01 pattern).
     *
     * @return array<string, static> the fresh codes by placement
     */
    public static function replaceAllPlacements(Business $business): array
    {
        return DB::transaction(function () use ($business) {
            $actives = static::query()
                ->where('business_id', $business->id)
                ->where('state', 'active')
                ->lockForUpdate()
                ->get();

            // [ON-SPOT-3]: each fresh code inherits its
            // placement's owner-set name.
            $labels = [];
            foreach ($actives as $code) {
                if ($code->placement !== null && $code->label !== null) {
                    $labels[$code->placement] = $code->label;
                }
                $code->forceFill(['state' => 'revoked', 'revoked_at' => now()])->save();
            }

            $fresh = [];
            foreach (self::PLACEMENTS as $placement) {
                self::issueFor($business, $labels[$placement] ?? null, $placement);
                $fresh[$placement] = self::activeForPlacement($business, $placement);
            }

            return $fresh;
        });
    }

    /**
     * The plaintext token for owner-facing rendering (the QR page
     * and poster downloads, spec Section 5.4). Null for codes issued
     * before the sealed copy existed.
     */
    public function plainToken(): ?string
    {
        return $this->token_encrypted;
    }
}
