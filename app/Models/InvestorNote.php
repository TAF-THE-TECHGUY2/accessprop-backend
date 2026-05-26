<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestorNote extends Model
{
    protected $guarded = ['id'];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }
}
