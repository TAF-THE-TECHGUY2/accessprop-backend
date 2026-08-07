<?php

namespace App\Console\Commands;

use App\Models\FundHolding;
use App\Models\FundTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Converts pre-ledger fund_holdings summary rows into subscription transactions.
 *
 * Each holding becomes exactly one synthetic subscription. This is lossy by
 * necessity: a summary row that blended several investments cannot be split back
 * into them. What the ledger gains is a stable, auditable base to build forward
 * from — not a reconstruction of history that was never recorded.
 */
class BackfillFundTransactions extends Command
{
    protected $signature = 'funds:backfill-transactions
                            {--dry-run : Report what would be written without writing it}';

    protected $description = 'Backfill fund_transactions from existing fund_holdings summary rows';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $holdings = FundHolding::with(['investor', 'fund'])->get();

        if ($holdings->isEmpty()) {
            $this->info('No fund_holdings rows found. Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d holding(s).',
            $dryRun ? 'Would back fill' : 'Backfilling',
            $holdings->count()
        ));
        $this->newLine();

        $written = 0;
        $skipped = 0;
        $partial = [];

        foreach ($holdings as $holding) {
            $label = sprintf(
                'investor=%s fund=%s',
                $holding->investor->code ?? "id:{$holding->investor_id}",
                $holding->fund->code ?? "id:{$holding->fund_id}"
            );

            $existing = FundTransaction::where('investor_id', $holding->investor_id)
                ->where('fund_id', $holding->fund_id)
                ->exists();

            if ($existing) {
                $this->line("  <comment>skip</comment>  {$label} — ledger rows already exist");
                $skipped++;

                continue;
            }

            $units = (float) $holding->units;
            $invested = (float) $holding->amount_invested;

            if ($units <= 0 || $invested <= 0) {
                $this->line("  <comment>skip</comment>  {$label} — zero units or zero invested");
                $skipped++;

                continue;
            }

            // average_unit_price is what the pre-ledger code recorded. Where it is
            // missing or inconsistent, derive it so units × price reconciles to
            // the amount actually invested.
            $recorded = (float) $holding->average_unit_price;
            $derived = round($invested / $units, 4);
            $price = $recorded > 0 ? $recorded : $derived;

            $reasons = [];

            if ($recorded <= 0) {
                $reasons[] = 'average_unit_price was missing; derived from amount ÷ units';
            } elseif (abs($recorded - $derived) > 0.01) {
                $reasons[] = sprintf(
                    'average_unit_price %.4f disagrees with amount ÷ units %.4f; kept the recorded value',
                    $recorded,
                    $derived
                );
            }

            if (! $holding->first_invested_at) {
                $reasons[] = 'first_invested_at was null; used the holding created_at';
            }

            // premium_pct is null on every backfilled row. This is not lost data —
            // the pre-ledger write path had no premium concept at all. It minted
            // units at latest NAV, so book value and price paid were identical by
            // construction. Whether real-world entries carried a premium the
            // system never captured is a question for the fund manager.
            $reasons[] = 'premium_pct null — the pre-ledger write path never captured a premium';

            $date = ($holding->first_invested_at ?? $holding->created_at)->toDateString();

            if (! $dryRun) {
                DB::transaction(function () use ($holding, $units, $invested, $price, $date) {
                    FundTransaction::create([
                        'investor_id' => $holding->investor_id,
                        'fund_id' => $holding->fund_id,
                        'transaction_date' => $date,
                        'type' => FundTransaction::TYPE_SUBSCRIPTION,
                        'units' => round($units, 6),
                        'book_value_at_purchase' => $price,
                        'premium_pct' => null,
                        'price_per_unit' => $price,
                        'gross_amount' => round($invested, 2),
                        'source' => 'backfill:fund_holdings#'.$holding->id,
                    ]);
                });
            }

            $this->line(sprintf(
                '  <info>%s</info>  %s — %s units @ %s on %s (%s)',
                $dryRun ? 'plan' : 'write',
                $label,
                rtrim(rtrim(number_format($units, 6, '.', ''), '0'), '.'),
                number_format($price, 4),
                $date,
                '$'.number_format($invested, 2)
            ));

            foreach ($reasons as $reason) {
                $this->line("           <comment>note</comment> {$reason}");
            }

            $partial[$label] = $reasons;
            $written++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d written, %d skipped.',
            $dryRun ? 'Dry run complete' : 'Backfill complete',
            $written,
            $skipped
        ));

        if ($partial !== []) {
            $this->newLine();
            $this->warn('Rows that could not be fully reconstructed:');
            foreach ($partial as $label => $reasons) {
                $this->line("  {$label}");
                foreach ($reasons as $reason) {
                    $this->line("    - {$reason}");
                }
            }
            $this->newLine();
            $this->warn(
                'Every backfilled row is a single synthetic subscription. Holdings that '.
                'blended multiple investments cannot be split back into them.'
            );
        }

        return self::SUCCESS;
    }
}
