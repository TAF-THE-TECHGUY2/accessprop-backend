<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestorDocument extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class, 'investor_profile_id');
    }
}
