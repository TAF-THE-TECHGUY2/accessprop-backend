<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundUnitPrice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'decimal:4',
        'as_of_date' => 'date',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }
}
