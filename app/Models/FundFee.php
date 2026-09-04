<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundFee extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'ownership_pct' => 'decimal:6',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    /**
     * Fees hang off (fund_id, investor_id), not fund_holdings — that table is a
     * rebuildable read cache and must not own financial history.
     */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    /**
     * The declared quarterly total this allocation is a share of. Null on rows
     * predating the declaration model.
     */
    public function declaration(): BelongsTo
    {
        return $this->belongsTo(FundFeeDeclaration::class, 'fee_declaration_id');
    }
}
