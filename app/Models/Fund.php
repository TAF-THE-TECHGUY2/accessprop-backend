<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fund extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'inception_date' => 'date',
        'minimum_investment' => 'decimal:2',
    ];

    public function holdings(): HasMany
    {
        return $this->hasMany(FundHolding::class);
    }

    public function unitPrices(): HasMany
    {
        return $this->hasMany(FundUnitPrice::class)->orderBy('as_of_date');
    }

    public function currentUnitPrice(): ?FundUnitPrice
    {
        return $this->unitPrices()->latest('as_of_date')->first();
    }
}
