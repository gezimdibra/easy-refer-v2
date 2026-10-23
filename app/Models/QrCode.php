<?php

namespace App\Models;

use App\Support\OpaqueToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business QR code (spec Section 7.2). The raw token exists only in
 * the printed code and the customer's link; the row stores its hash.
 */
class QrCode extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'token_hash',
        'token_encrypted',
        'state',
        'label',
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
     * Issue the business's launch QR code and return the raw token,
     * which the caller surfaces exactly once (the printable code). The
     * token itself is never stored or logged.
     */
    public static function issueFor(Business $business, ?string $label = null): string
    {
        $token = OpaqueToken::generate();

        static::create([
            'business_id' => $business->id,
            'token_hash' => OpaqueToken::hash($token),
            'token_encrypted' => $token,
            'state' => 'active',
            'label' => $label,
        ]);

        return $token;
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
