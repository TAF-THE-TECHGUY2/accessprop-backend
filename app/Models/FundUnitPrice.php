<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundUnitPrice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        // The column has held 8 decimal places since August; reading it at 4
        // rounded every NAV the app priced units from. The Valuation and Unit
        // Pricing Policy prices subscriptions on the unrounded Base NAV.
        'price' => 'decimal:8',
        'as_of_date' => 'date',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }
}
