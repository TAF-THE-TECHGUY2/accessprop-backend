<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A quarterly fee as the accountant calculated it.
 *
 * total_amount is authoritative and is never derived. The allocations are
 * derived, and can be recomputed from this row if a holding changes.
 */
class FundFeeDeclaration extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_amount' => 'decimal:2',
        'gross_asset_value' => 'decimal:2',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FundFee::class, 'fee_declaration_id');
    }

    /**
     * Whether the allocations still add up to what was declared.
     *
     * Cheap to check and worth checking: a set of investor fees that does not
     * sum to the accountant's figure is exactly the discrepancy this design
     * exists to prevent.
     */
    public function allocationsReconcile(): bool
    {
        return round((float) $this->allocations()->sum('amount'), 2)
            === round((float) $this->total_amount, 2);
    }
}
