<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SigningLink extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token', 'token_hash'];

    protected $casts = [
        'token' => 'encrypted',
        'requested_at' => 'datetime',
        'last_emailed_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SigningEnvelope::class, 'signing_envelope_id');
    }

    public static function issue(SigningEnvelope $envelope, string $role): self
    {
        $token = Str::random(64);

        return static::firstOrCreate(
            ['signing_envelope_id' => $envelope->id, 'role' => $role],
            ['token' => $token, 'token_hash' => hash('sha256', $token)],
        );
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', hash('sha256', $token))->first();
    }

    /** Where the email's button points: the portal's signing page. */
    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/sign/'.$this->token;
    }
}
