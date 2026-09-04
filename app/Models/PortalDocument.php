<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class PortalDocument extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'document_dated_at' => 'datetime',
        'file_size_bytes' => 'integer',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    /**
     * Documents this investor may see: global ones, fund-scoped ones for a fund
     * they can access, and ones addressed to them personally.
     *
     * A scope rather than a check performed after loading, so a document
     * belonging to someone else is absent from the result rather than found and
     * then rejected — the two are indistinguishable to the caller, which is the
     * point.
     */
    public function scopeVisibleTo(Builder $query, Investor $investor, Collection $fundIds): Builder
    {
        return $query->where(function (Builder $q) use ($investor, $fundIds) {
            $q->where('scope', 'global')
                ->orWhere(function (Builder $q2) use ($fundIds) {
                    $q2->where('scope', 'fund')->whereIn('fund_id', $fundIds);
                })
                ->orWhere(function (Builder $q3) use ($investor) {
                    $q3->where('scope', 'investor')->where('investor_id', $investor->id);
                });
        });
    }
}
