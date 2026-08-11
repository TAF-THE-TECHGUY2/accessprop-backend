<?php

namespace App\Console\Commands;

use App\Models\EmailLog;
use App\Models\Investor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes investors and everything attached to them.
 *
 * Every foreign key pointing at investors cascades, so relational data goes with
 * the row. Three tables have no foreign key and would be left orphaned, so they
 * are cleaned up explicitly:
 *
 *   personal_access_tokens  polymorphic (tokenable_type/tokenable_id), no FK
 *   email_logs              keyed by recipient email string
 *   password_reset_tokens   keyed by email
 *
 * Defaults to a dry run. Nothing is deleted without --force.
 */
class ClearInvestors extends Command
{
    protected $signature = 'investors:clear
                            {--force : Actually delete. Without this the command only reports.}
                            {--code=* : Limit to these investor codes.}
                            {--except=* : Preserve these investor codes.}
                            {--keep-email-logs : Leave email_logs rows in place.}';

    protected $description = 'Delete investors and all attached records (dry run unless --force)';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $codes = (array) $this->option('code');
        $except = (array) $this->option('except');

        $query = Investor::query();

        if ($codes !== []) {
            $query->whereIn('code', $codes);
        }

        if ($except !== []) {
            $query->whereNotIn('code', $except);
        }

        $investors = $query->orderBy('code')->get();

        if ($investors->isEmpty()) {
            $this->info('No investors match. Nothing to do.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($force
            ? '<fg=red;options=bold>DELETING</> the following investors:'
            : '<comment>DRY RUN</comment> — these investors WOULD be deleted:');
        $this->newLine();

        $emails = [];

        foreach ($investors as $investor) {
            $emails[] = $investor->email;

            $this->line(sprintf(
                '  %-10s %-34s %s',
                $investor->code,
                $investor->email,
                $investor->investment_status ?? '—',
            ));

            $counts = [
                'ledger' => DB::table('fund_transactions')->where('investor_id', $investor->id)->count(),
                'holdings' => DB::table('fund_holdings')->where('investor_id', $investor->id)->count(),
                'distributions' => DB::table('distributions')->where('investor_id', $investor->id)->count(),
                'fees' => DB::table('fund_fees')->where('investor_id', $investor->id)->count(),
                'documents' => DB::table('investor_documents')->where('investor_id', $investor->id)->count(),
                'activities' => DB::table('investor_activities')->where('investor_id', $investor->id)->count(),
                'tokens' => DB::table('personal_access_tokens')
                    ->where('tokenable_type', Investor::class)
                    ->where('tokenable_id', $investor->id)
                    ->count(),
            ];

            $attached = collect($counts)->filter()->map(fn ($n, $k) => "{$k}={$n}")->implode('  ');

            if ($attached !== '') {
                $this->line('             <fg=gray>'.$attached.'</>');
            }
        }

        $emailLogCount = $this->option('keep-email-logs')
            ? 0
            : EmailLog::whereIn('recipient', $emails)->count();

        $orphanedTokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', Investor::class)
            ->whereNotIn('tokenable_id', Investor::query()->select('id'))
            ->count();

        $this->newLine();
        $this->line(sprintf(
            '  %d investor(s), %d email log(s), plus all cascaded records.',
            $investors->count(),
            $emailLogCount,
        ));

        if ($orphanedTokens > 0) {
            $this->line(sprintf(
                '  %d orphaned access token(s) from earlier deletions will also be removed.',
                $orphanedTokens,
            ));
        }

        if (! $force) {
            $this->newLine();
            $this->warn('Dry run — nothing deleted. Re-run with --force to proceed.');

            return self::SUCCESS;
        }

        $this->newLine();
        if (! $this->confirm('This cannot be undone. Delete them?', false)) {
            $this->info('Aborted. Nothing deleted.');

            return self::SUCCESS;
        }

        $deleted = 0;

        DB::transaction(function () use ($investors, $emails, &$deleted) {
            foreach ($investors as $investor) {
                // Polymorphic, so no cascade — must go before the investor row or
                // it is orphaned with a dangling tokenable_id that would
                // authenticate against whatever later takes that id.
                $investor->tokens()->delete();

                $investor->delete();
                $deleted++;
            }

            if (! $this->option('keep-email-logs')) {
                EmailLog::whereIn('recipient', $emails)->delete();
            }

            // Keyed by email, no FK.
            DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
        });

        // Tokens left behind by earlier deletions that did not clean them up.
        // Not tidiness: tokenable_id is not a foreign key, so a dangling token
        // authenticates as whichever investor later receives that id.
        $orphaned = DB::table('personal_access_tokens')
            ->where('tokenable_type', Investor::class)
            ->whereNotIn('tokenable_id', Investor::query()->select('id'))
            ->delete();

        if ($orphaned > 0) {
            $this->warn(sprintf(
                'Also removed %d orphaned access token(s) from earlier deletions — a dangling token would authenticate as whichever investor next receives that id.',
                $orphaned,
            ));
        }

        $this->newLine();
        $this->info(sprintf('Deleted %d investor(s) and all attached records.', $deleted));
        $this->line('  remaining investors: '.Investor::count());

        return self::SUCCESS;
    }
}
