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
}
