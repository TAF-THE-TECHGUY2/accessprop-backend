<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestorMessage extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }
}
