<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Derived read cache of an investor's position in a fund.
 *
 * fund_transactions is the source of truth. Never write to this table directly —
 * append to the ledger and call rebuildFromLedger().
 */
class FundHolding extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'units' => 'decimal:6',
        'amount_invested' => 'decimal:2',
        'average_unit_price' => 'decimal:4',
        'first_invested_at' => 'datetime',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    /**
     * Recompute this investor/fund position from the ledger.
     *
     * Uses updateOrCreate and never deletes. A zero-unit holding is still
     * meaningful: documents and communications gating reads this table, and a
     * fully-redeemed investor must not silently lose access to their records.
     */
    public static function rebuildFromLedger(int $investorId, int $fundId): ?self
    {
        $transactions = FundTransaction::query()
            ->where('investor_id', $investorId)
            ->where('fund_id', $fundId)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        if ($transactions->isEmpty()) {
            return null;
        }

        // Units are signed, so redemptions subtract naturally.
        $units = round((float) $transactions->sum('units'), 6);

        // Cost basis counts inflows only — a redemption must not inflate it.
        $invested = round(
            (float) $transactions
                ->filter(fn (FundTransaction $t) => $t->isInflow())
                ->sum('gross_amount'),
            2
        );

        return static::updateOrCreate(
            ['investor_id' => $investorId, 'fund_id' => $fundId],
            [
                'units' => $units,
                'amount_invested' => $invested,
                'average_unit_price' => $units > 0 ? round($invested / $units, 4) : 0,
                'first_invested_at' => $transactions->first()->transaction_date,
            ]
        );
    }

    /**
     * Distributions are keyed to (fund_id, investor_id), not to this row — the
     * cache is rebuildable and must not own financial history.
     */
    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class, 'investor_id', 'investor_id')
            ->where('fund_id', $this->fund_id)
            ->orderByDesc('paid_at');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(FundFee::class, 'investor_id', 'investor_id')
            ->where('fund_id', $this->fund_id)
            ->orderByDesc('period_end');
    }

    public function totalDistributions(): float
    {
        return (float) $this->distributions()->sum('amount');
    }

    public function totalAumFees(): float
    {
        return (float) $this->fees()->where('fee_type', 'aum')->sum('amount');
    }

    public function totalPerformanceFees(): float
    {
        return (float) $this->fees()->where('fee_type', 'performance')->sum('amount');
    }
}
