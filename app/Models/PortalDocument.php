<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
