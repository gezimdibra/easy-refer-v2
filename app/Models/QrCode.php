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
            'state' => 'active',
            'label' => $label,
        ]);

        return $token;
    }
}
