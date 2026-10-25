<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A billing plan (spec Sections 7.2, 9.2). Prices live here and only
 * here: every rendered or charged amount derives from these rows,
 * never from a literal in a template or a request.
 */
class Plan extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'model',
        'price_cents',
        'per_referral_cents',
        'included_referrals_per_period',
        'monthly_cap_cents',
        'square_plan_variation_id',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'per_referral_cents' => 'integer',
            'included_referrals_per_period' => 'integer',
            'monthly_cap_cents' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'current_plan_id');
    }

    /**
     * The price one verified visit carries under this plan, which
     * is the figure billing surfaces state as the per-visit price.
     * Each price column means exactly one thing: per_referral_cents
     * is the per-visit price of the per-referral model, and
     * price_cents is the subscription model's period price, never
     * a per-visit figure. A subscription plan therefore has no
     * per-visit price (null), and no surface may fall back to
     * formatting price_cents as one (the FLOWS-F1 "$0.00 per visit"
     * defect came from exactly that read).
     */
    public function perVisitPriceCents(): ?int
    {
        return $this->model === 'per_referral' ? $this->per_referral_cents : null;
    }

    /**
     * The single plan shown at launch (OD-1). Inactive seed rows
     * render nowhere.
     */
    public static function launch(): ?self
    {
        return static::query()->where('key', 'pay_per_visit')->where('active', true)->first();
    }
}
