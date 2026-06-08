<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundingInstruction extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'released_at' => 'datetime',
        'provider_payload' => 'array',
        'amount_cents' => 'integer',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class, 'investor_profile_id');
    }
}
