<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Investor extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'investment_last_distribution' => 'datetime',
        'investment_amount' => 'decimal:2',
        'investment_commitment' => 'decimal:2',
        'investment_funded' => 'decimal:2',
        'password' => 'hashed',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(InvestorDocument::class, 'investor_profile_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(InvestorActivity::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InvestorMessage::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(InvestorNote::class);
    }

    public function integrationRequests(): HasMany
    {
        return $this->hasMany(IntegrationRequest::class, 'investor_profile_id');
    }

    public function fundingInstructions(): HasMany
    {
        return $this->hasMany(FundingInstruction::class, 'investor_profile_id');
    }

    public function paymentConfirmations(): HasMany
    {
        return $this->hasMany(PaymentConfirmation::class, 'investor_profile_id');
    }

    public function partnerMatches(): HasMany
    {
        return $this->hasMany(PartnerMatch::class, 'investor_profile_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(InvestorActivityLog::class, 'investor_profile_id');
    }

    public function signingEnvelopes(): HasMany
    {
        return $this->hasMany(SigningEnvelope::class);
    }
}
