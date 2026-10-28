<?php

namespace App\Support;

use App\Models\Investor;
use App\Models\TwoFactorTrustedDevice;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator-app sign-in (TOTP, RFC 6238).
 *
 * Any authenticator app works — Microsoft Authenticator, Google Authenticator,
 * Authy, 1Password — because they all implement the same standard. Nothing
 * here talks to Microsoft or Google.
 *
 * Sign-in becomes two requests when an account has it enabled: the password
 * step returns a short-lived, encrypted challenge instead of a token, and the
 * code step trades that challenge for the token. The challenge names the
 * account type as well as the id, so an investor's challenge can never be
 * redeemed on the admin endpoint or the other way round.
 */
class TwoFactor
{
    /** How long the gap between password and code may be. */
    private const CHALLENGE_MINUTES = 5;

    /** "Remember this device". */
    public const TRUSTED_DEVICE_DAYS = 30;

    private const RECOVERY_CODE_COUNT = 8;

    private const TYPES = [
        'admin' => User::class,
        'investor' => Investor::class,
    ];

    public function __construct(private Google2FA $google2fa = new Google2FA)
    {
        // One 30-second step either side, for a phone clock that has drifted.
        $this->google2fa->setWindow(1);
    }

    // ---- Enrolment -------------------------------------------------------

    /**
     * Starts (or restarts) setup. Until confirm() succeeds the account is not
     * protected, and sign-in does not ask for a code.
     */
    public function beginSetup(Model $account): array
    {
        $secret = $this->google2fa->generateSecretKey(32);

        $account->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_timestep' => null,
        ])->save();

        return $this->provisioning($account);
    }

    /**
     * What an authenticator app scans: the account's existing secret as a QR
     * code and as text. Every phone that scans it produces the same codes, so
     * showing it again is how another device is added — nothing is reset and
     * the phones already set up keep working.
     */
    public function provisioning(Model $account): array
    {
        $secret = $account->two_factor_secret;
        $url = $this->google2fa->getQRCodeUrl('Access Properties', $account->email, $secret);

        return [
            'secret' => $secret,
            'otpauthUrl' => $url,
            'qrSvg' => $this->qrSvg($url),
        ];
    }

    /**
     * Proves the app was set up correctly by checking one code from it, then
     * switches protection on. Returns the recovery codes, which are shown once.
     *
     * @return array<int, string>|null null when the code is wrong
     */
    public function confirm(Model $account, string $code): ?array
    {
        if ($account->two_factor_secret === null || ! $this->verifyCode($account, $code)) {
            return null;
        }

        $codes = $this->freshRecoveryCodes();

        $account->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

        // Turning it back on withdraws an earlier decision to go without.
        if ($account->getAttribute('two_factor_opted_out_at') !== null) {
            $account->forceFill(['two_factor_opted_out_at' => null])->save();
        }

        return $codes;
    }

    /** @return array<int, string> */
    public function regenerateRecoveryCodes(Model $account): array
    {
        $codes = $this->freshRecoveryCodes();
        $account->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    /** Turns it off and forgets every trusted device. */
    public function disable(Model $account): void
    {
        $account->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();

        $account->trustedDevices()->delete();
    }

    public function status(Model $account): array
    {
        return [
            'enabled' => $account->hasTwoFactorEnabled(),
            'setupRequired' => $account->needsTwoFactorSetup(),
            'optedOutAt' => optional($account->getAttribute('two_factor_opted_out_at'))->toIso8601String(),
            'confirmedAt' => optional($account->two_factor_confirmed_at)->toIso8601String(),
            'recoveryCodesLeft' => count($account->two_factor_recovery_codes ?? []),
            'trustedDevices' => $account->trustedDevices()
                ->where('expires_at', '>', now())
                ->count(),
        ];
    }

    // ---- Codes -----------------------------------------------------------

    /**
     * Checks a 6-digit code from the app. A code is accepted once: the window
     * it belongs to is recorded, and that window or any earlier one is refused
     * afterwards.
     */
    public function verifyCode(Model $account, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{6}$/', $code) || $account->two_factor_secret === null) {
            return false;
        }

        // Never null: with no previous step the library returns `true` instead
        // of the step it matched, and the step is what has to be remembered.
        $timestep = $this->google2fa->verifyKeyNewer(
            $account->two_factor_secret,
            $code,
            $account->two_factor_last_timestep ?? 0,
        );

        if ($timestep === false) {
            return false;
        }

        $account->forceFill(['two_factor_last_timestep' => $timestep])->save();

        return true;
    }

    /** Spends a recovery code. Each one works once. */
    public function useRecoveryCode(Model $account, string $code): bool
    {
        $code = strtoupper(trim($code));
        $codes = $account->two_factor_recovery_codes ?? [];

        foreach ($codes as $i => $candidate) {
            if (hash_equals($candidate, $code)) {
                unset($codes[$i]);
                $account->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    // ---- Sign-in challenge -----------------------------------------------

    public function makeChallenge(string $type, Model $account): string
    {
        return Crypt::encryptString(json_encode([
            't' => $type,
            'id' => $account->getKey(),
            'exp' => now()->addMinutes(self::CHALLENGE_MINUTES)->timestamp,
        ]));
    }

    /** The account the challenge was issued for, or null if it is forged, stale or for the other side. */
    public function resolveChallenge(string $type, ?string $challenge): ?Model
    {
        if (! $challenge || ! isset(self::TYPES[$type])) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($challenge), true);
        } catch (DecryptException) {
            return null;
        }

        if (($payload['t'] ?? null) !== $type || ($payload['exp'] ?? 0) < now()->timestamp) {
            return null;
        }

        $account = (self::TYPES[$type])::find($payload['id'] ?? null);

        return $account && $account->hasTwoFactorEnabled() ? $account : null;
    }

    // ---- Trusted devices -------------------------------------------------

    /** Returns the raw token for the browser to keep; only its hash is stored. */
    public function trustDevice(Model $account, ?string $userAgent): string
    {
        $token = Str::random(64);

        $account->trustedDevices()->create([
            'token_hash' => hash('sha256', $token),
            'user_agent' => $userAgent ? Str::limit($userAgent, 500, '') : null,
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::TRUSTED_DEVICE_DAYS),
        ]);

        return $token;
    }

    public function isTrustedDevice(Model $account, ?string $token): bool
    {
        if (! $token) {
            return false;
        }

        $device = TwoFactorTrustedDevice::where('token_hash', hash('sha256', $token))
            ->where('authenticatable_type', $account->getMorphClass())
            ->where('authenticatable_id', $account->getKey())
            ->where('expires_at', '>', now())
            ->first();

        $device?->forceFill(['last_used_at' => now()])->save();

        return $device !== null;
    }

    public function forgetDevices(Model $account): void
    {
        $account->trustedDevices()->delete();
    }

    // ---- Internals -------------------------------------------------------

    /** @return array<int, string> e.g. "K7QX-2MPD-9TRA" */
    private function freshRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I to misread

        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => implode('-', array_map(
                fn () => collect(range(1, 4))->map(fn () => $alphabet[random_int(0, 31)])->implode(''),
                range(1, 3),
            )))
            ->all();
    }

    private function qrSvg(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));

        return $writer->writeString($url);
    }
}
