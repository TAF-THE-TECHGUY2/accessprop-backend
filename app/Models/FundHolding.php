<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class)->orderByDesc('paid_at');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(FundFee::class)->orderByDesc('period_end');
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
