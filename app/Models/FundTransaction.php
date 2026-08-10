<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transaction-level ledger of an investor's position in a fund.
 *
 * This is the source of truth. fund_holdings is a derived read cache recomputed
 * from these rows — never write to it directly.
 */
class FundTransaction extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'transaction_date' => 'date',
        'date_oa_mipa_signed' => 'date',
        'units' => 'decimal:6',
        'book_value_at_purchase' => 'decimal:8',
        'premium_pct' => 'decimal:3',
        'price_per_unit' => 'decimal:8',
        'gross_amount' => 'decimal:2',
    ];

    public const TYPE_SUBSCRIPTION = 'subscription';

    public const TYPE_REINVESTMENT = 'reinvestment';

    public const TYPE_REDEMPTION = 'redemption';

    public const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Types that represent capital flowing in. Used when deriving
     * amount_invested — redemptions and adjustments must not inflate cost basis.
     */
    public const INFLOW_TYPES = [
        self::TYPE_SUBSCRIPTION,
        self::TYPE_REINVESTMENT,
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function isInflow(): bool
    {
        return in_array($this->type, self::INFLOW_TYPES, true);
    }
}
