<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'newsletter_opted_in' => 'boolean',
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

    public function threads(): HasMany
    {
        return $this->hasMany(MessageThread::class)->orderByDesc('last_message_at');
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(FundHolding::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FundTransaction::class);
    }

    /**
     * Capital this investor has actually contributed, summed from the ledger.
     *
     * None of the three columns on this table can answer the question.
     * investment_amount and investment_commitment record what the investor
     * said they would put in when they signed up and are never revised, so a
     * top-up leaves them showing the first deposit alone. investment_funded is
     * a denormalised running total that earlier write paths maintained
     * inconsistently — it is still ahead of the ledger for investors whose
     * deposits were recorded before it was derived, and non-zero for investors
     * who have no ledger rows at all.
     *
     * fund_transactions is the source of truth, so the total is taken from
     * there. Redemptions are excluded for the same reason FundHolding excludes
     * them: this is capital contributed, not a net balance.
     */
    public function contributedCapital(): float
    {
        // Set by scopeWithContributedCapital(); using it keeps a list of
        // investors to one query instead of one per row.
        if (array_key_exists('contributed_capital', $this->attributes)) {
            return (float) $this->attributes['contributed_capital'];
        }

        return (float) $this->transactions()
            ->whereIn('type', FundTransaction::INFLOW_TYPES)
            ->sum('gross_amount');
    }

    public function scopeWithContributedCapital(Builder $query): Builder
    {
        return $query->withSum(
            ['transactions as contributed_capital' => fn ($q) => $q->whereIn('type', FundTransaction::INFLOW_TYPES)],
            'gross_amount',
        );
    }

    public function portalDocuments(): HasMany
    {
        return $this->hasMany(PortalDocument::class);
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
