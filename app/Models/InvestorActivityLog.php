<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestorActivityLog extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class, 'investor_profile_id');
    }
}
