<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operator-review row (G1 design pass §4): the gate fallback
 * (kind=gate_fallback) or a contested claim (kind=contested). A
 * review creates NO account; approval only opens the §3.2 hand-off
 * for the requesting identity, single-use, for 72 hours.
 */
class ClaimReview extends Model
{
    public const KIND_GATE_FALLBACK = 'gate_fallback';
    public const KIND_CONTESTED = 'contested';

    protected $fillable = [
        'claimable_page_id', 'kind', 'requester_name', 'contact_phone_e164',
        'identity_hash', 'status', 'handoff_token_hash', 'handoff_expires_at', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['handoff_expires_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(ClaimablePage::class, 'claimable_page_id');
    }

    public static function identityHash(string $name, ?string $phoneE164): string
    {
        return hash('sha256', mb_strtolower(trim($name)).'|'.preg_replace('/\D+/', '', (string) $phoneE164));
    }
}
