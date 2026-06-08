<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundFee extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function fundHolding(): BelongsTo
    {
        return $this->belongsTo(FundHolding::class);
    }
}
