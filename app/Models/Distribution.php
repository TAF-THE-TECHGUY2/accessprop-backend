<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Distribution extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /**
     * Distributions hang off (fund_id, investor_id), not fund_holdings — that
     * table is a rebuildable read cache and must not own financial history.
     */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }
}
