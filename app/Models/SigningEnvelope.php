<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SigningEnvelope extends Model
{
    public const STATUS_CREATED = 'created';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_SIGNED_BY_INVESTOR = 'signed_by_investor';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_VOIDED = 'voided';

    protected $guarded = ['id'];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'signed_at' => 'datetime',
        'completed_at' => 'datetime',
        'voided_at' => 'datetime',
        'last_event_payload' => 'array',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }
}
