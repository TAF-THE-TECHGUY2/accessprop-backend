<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for changes to a fund's issuance premium.
 *
 * Written only by Fund::changePremium(). Never edited or deleted — this is the
 * record that explains why two investors subscribing weeks apart paid different
 * prices for the same units.
 */
class FundPremiumChange extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'old_premium_pct' => 'decimal:3',
        'new_premium_pct' => 'decimal:3',
        'changed_at' => 'datetime',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }
}
