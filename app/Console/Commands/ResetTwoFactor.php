<?php

namespace App\Console\Commands;

use App\Models\Investor;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Console\Command;

/**
 * For an admin locked out of their authenticator with no recovery codes left.
 * There is no admin-users screen to do it from, and an admin cannot reset
 * their own from inside the console when they cannot sign in to it.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'two-factor:reset {email : The admin (or investor, with --investor) to reset} {--investor : Reset an investor instead of an admin}';

    protected $description = 'Switch off two-factor sign-in for one account so it can be set up again';

    public function handle(TwoFactor $twoFactor): int
    {
        $model = $this->option('investor') ? Investor::class : User::class;
        $account = $model::where('email', $this->argument('email'))->first();

        if (! $account) {
            $this->error('No '.($this->option('investor') ? 'investor' : 'admin').' with that email.');

            return self::FAILURE;
        }

        if (! $this->confirm("Reset two-factor sign-in for {$account->email}?", true)) {
            return self::FAILURE;
        }

        $twoFactor->disable($account);
        $account->tokens()->delete();

        $this->info('Done. Their sessions were signed out; they will be asked to set it up again at next sign-in.');

        return self::SUCCESS;
    }
}
