<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InvestorActivity extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    /**
     * A unique code for a new activity. The code is unique in the table and
     * used to be the investor code plus the second, so two activities for one
     * investor in the same second — an admin clicking twice, a status change
     * straight after signup — failed the insert and the request with it.
     */
    public static function newCode(string $investorCode): string
    {
        return 'act-'.$investorCode.'-'.now()->timestamp.'-'.Str::lower(Str::random(6));
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }
}
