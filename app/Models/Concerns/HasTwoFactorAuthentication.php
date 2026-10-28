<?php

namespace App\Models\Concerns;

use App\Models\TwoFactorTrustedDevice;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared by admins (User) and investors. The behaviour lives in
 * App\Support\TwoFactor; this only gives each model its columns and devices.
 */
trait HasTwoFactorAuthentication
{
    public function initializeHasTwoFactorAuthentication(): void
    {
        $this->mergeCasts([
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ]);

        $this->makeHidden(['two_factor_secret', 'two_factor_recovery_codes']);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /** Whether this account is held at the setup screen. Admins: until it is on. */
    public function needsTwoFactorSetup(): bool
    {
        return ! $this->hasTwoFactorEnabled();
    }

    public function trustedDevices(): MorphMany
    {
        return $this->morphMany(TwoFactorTrustedDevice::class, 'authenticatable');
    }
}
