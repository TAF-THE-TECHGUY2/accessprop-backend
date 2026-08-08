<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Fund extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'inception_date' => 'date',
        'minimum_investment' => 'decimal:2',
        'current_premium_pct' => 'decimal:3',
        'aum_fee_annual_pct' => 'decimal:3',
    ];

    public function holdings(): HasMany
    {
        return $this->hasMany(FundHolding::class);
    }

    public function unitPrices(): HasMany
    {
        return $this->hasMany(FundUnitPrice::class)->orderBy('as_of_date');
    }

    /**
     * Most recently published unit price.
     *
     * reorder() is required: the unitPrices() relation applies orderBy ascending,
     * and chaining latest() only appends a second ORDER BY term. MySQL honours
     * the first, so this previously returned the OLDEST price — meaning units
     * were minted at the inception valuation while the portal displayed the
     * current one.
     */
    public function currentUnitPrice(): ?FundUnitPrice
    {
        return $this->unitPrices()->reorder()->orderByDesc('as_of_date')->first();
    }

    /**
     * Book value in force on a given date — the most recent published price at
     * or before it.
     *
     * Pricing an investment at today's book value is wrong for anything not
     * bought today. A launch investor who put in $100,000 at $10.00 must be
     * recorded as 10,000 units, not $100,000 ÷ today's price. Backdated and
     * historical entries have to price at the date of investment.
     *
     * Returns null when the date precedes the first published price, which the
     * caller must treat as a hard failure rather than substituting a default.
     */
    public function bookValueAsOf(Carbon|string $date): ?FundUnitPrice
    {
        return $this->unitPrices()
            ->reorder()
            ->where('as_of_date', '<=', Carbon::parse($date)->toDateString())
            ->orderByDesc('as_of_date')
            ->first();
    }

    /**
     * Sale price on a date: book value then, plus the premium supplied.
     *
     * The premium is passed in rather than read from the fund because
     * current_premium_pct is the live setting for new subscriptions and would be
     * wrong for a backdated entry.
     */
    public function salePriceAsOf(Carbon|string $date, float $premiumPct = 0.0): ?float
    {
        $book = $this->bookValueAsOf($date)?->price;

        if ($book === null) {
            return null;
        }

        return round((float) $book * (1 + ($premiumPct / 100)), 4);
    }

    public function premiumChanges(): HasMany
    {
        return $this->hasMany(FundPremiumChange::class)->orderByDesc('changed_at');
    }

    /**
     * Sale price for a subscription placed right now: book value plus the
     * current issuance premium.
     *
     * Only valid for new subscriptions. Never use this to value an existing
     * position — a holding is worth units × book value, and applying the
     * premium again would double-count it.
     */
    public function currentSalePrice(): ?float
    {
        $book = $this->currentUnitPrice()?->price;

        if ($book === null) {
            return null;
        }

        return round((float) $book * (1 + ((float) $this->current_premium_pct / 100)), 4);
    }

    /**
     * Change the issuance premium, recording who changed it and from what.
     *
     * Existing transactions are untouched by design — each froze its own
     * premium_pct at purchase.
     */
    public function changePremium(float $newPct, ?int $changedBy = null, ?string $reason = null): FundPremiumChange
    {
        $old = (float) $this->current_premium_pct;

        $change = $this->premiumChanges()->create([
            'old_premium_pct' => $old,
            'new_premium_pct' => $newPct,
            'reason' => $reason,
            'changed_by' => $changedBy,
            'changed_at' => now(),
        ]);

        $this->update(['current_premium_pct' => $newPct]);

        return $change;
    }
}
