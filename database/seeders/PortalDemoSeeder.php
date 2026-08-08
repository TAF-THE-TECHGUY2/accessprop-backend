<?php

namespace Database\Seeders;

use App\Models\Distribution;
use App\Models\Fund;
use App\Models\FundFee;
use App\Models\FundHolding;
use App\Models\FundTransaction;
use App\Models\FundUnitPrice;
use App\Models\Investor;
use App\Models\PortalDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the Annex 3 portal demo data: one fund, quarterly unit prices, an
 * active holding for inv-1019 backdated for a realistic performance story,
 * distributions, fees, and a starter set of portal documents.
 *
 * Idempotent — safe to re-run.
 */
class PortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $fund = Fund::updateOrCreate(
            ['code' => 'aref-i'],
            [
                'name' => 'Access Real Estate Fund I',
                'description' => 'A diversified real estate fund targeting stable income and capital appreciation through a portfolio of multi-family and commercial properties.',
                'fund_type' => 'Diversified Income',
                'status' => 'active',
                'inception_date' => '2024-01-01',
                'target_yield' => '8.0% target',
                'minimum_investment' => 10000,
            ],
        );

        // AREF I published book value, as supplied by the fund manager.
        //
        // Dated to quarter END, which is when the accountant publishes. Earlier
        // seeds used quarter-start dates and invented figures; both are replaced.
        //
        // The series is deliberately not monotonic — $11.74 falls to $10.24 across
        // Q4 2023 → Q1 2024, and $13.16 falls to $12.24 across Q2 → Q3 2025. Real
        // losses exist independent of any entry premium, and anything that assumes
        // book value only rises is wrong.
        //
        // These are book values, not sale prices. A sale price is book value plus
        // a per-issuance premium, recorded on the transaction rather than here.
        $prices = [
            ['2023-03-31', 10.00, 'Q1 2023'],
            ['2023-06-30', 11.03, 'Q2 2023'],
            ['2023-09-30', 11.11, 'Q3 2023'],
            ['2023-12-31', 11.74, 'Q4 2023'],
            ['2024-03-31', 10.24, 'Q1 2024'],
            ['2024-06-30', 10.57, 'Q2 2024'],
            ['2024-09-30', 10.62, 'Q3 2024'],
            ['2024-12-31', 10.49, 'Q4 2024'],
            ['2025-03-31', 12.50, 'Q1 2025'],
            ['2025-06-30', 13.16, 'Q2 2025'],
            ['2025-09-30', 12.24, 'Q3 2025'],
            ['2025-12-31', 12.65, 'Q4 2025'],
            ['2026-03-31', 12.85, 'Q1 2026'],
            ['2026-06-30', 13.25, 'Q2 2026'],
        ];

        // Clear first: the previous seed used quarter-start dates, so
        // updateOrCreate keyed on as_of_date would leave those rows behind and
        // interleave two incompatible series.
        FundUnitPrice::where('fund_id', $fund->id)
            ->whereNotIn('as_of_date', array_column($prices, 0))
            ->delete();

        foreach ($prices as [$date, $price, $label]) {
            FundUnitPrice::updateOrCreate(
                ['fund_id' => $fund->id, 'as_of_date' => $date],
                ['price' => $price, 'quarter_label' => $label],
            );
        }

        $investor = Investor::where('code', 'inv-1019')->first();
        if (! $investor) {
            $this->command->warn('inv-1019 not found; skipping holding seed.');
            return;
        }

        // A launch investor: subscribed at the Q1 2023 book value of $10.00 with no
        // premium. Price is read from the published series at the investment date
        // rather than hardcoded, so this stays correct if the series is revised.
        $entryDate = '2023-03-31';
        $entryPrice = (float) $fund->bookValueAsOf($entryDate)->price;
        $amount = (float) $investor->investment_commitment;

        FundTransaction::where('investor_id', $investor->id)
            ->where('fund_id', $fund->id)
            ->delete();

        FundTransaction::create([
            'investor_id' => $investor->id,
            'fund_id' => $fund->id,
            'transaction_date' => $entryDate,
            'type' => FundTransaction::TYPE_SUBSCRIPTION,
            'units' => round($amount / $entryPrice, 6),
            'book_value_at_purchase' => $entryPrice,
            'premium_pct' => null,
            'price_per_unit' => $entryPrice,
            'gross_amount' => round($amount, 2),
            'source' => 'seed:portal-demo',
        ]);

        $holding = FundHolding::rebuildFromLedger($investor->id, $fund->id);

        // Distributions — quarterly, 8% annualised on $25k = $500/quarter.
        $holding->distributions()->delete();
        $distributions = [
            ['2024-04-15', 500.00],
            ['2024-07-15', 500.00],
            ['2024-10-15', 500.00],
            ['2025-01-15', 500.00],
            ['2025-04-15', 525.00],
            ['2025-07-15', 525.00],
            ['2025-10-15', 525.00],
            ['2026-01-15', 540.00],
            ['2026-04-15', 540.00],
        ];
        foreach ($distributions as [$date, $amount]) {
            Distribution::create([
                'fund_id' => $fund->id,
                'investor_id' => $investor->id,
                'amount' => $amount,
                'paid_at' => Carbon::parse($date),
                'distribution_type' => 'income',
                'notes' => 'Quarterly income distribution',
            ]);
        }

        // Fees — 1.5% AUM annually = ~$93.75/quarter.
        $holding->fees()->delete();
        $quarters = [
            ['2024-01-01', '2024-03-31'],
            ['2024-04-01', '2024-06-30'],
            ['2024-07-01', '2024-09-30'],
            ['2024-10-01', '2024-12-31'],
            ['2025-01-01', '2025-03-31'],
            ['2025-04-01', '2025-06-30'],
            ['2025-07-01', '2025-09-30'],
            ['2025-10-01', '2025-12-31'],
            ['2026-01-01', '2026-03-31'],
        ];
        foreach ($quarters as [$start, $end]) {
            FundFee::create([
                'fund_id' => $fund->id,
                'investor_id' => $investor->id,
                'fee_type' => 'aum',
                'amount' => 93.75,
                'period_start' => $start,
                'period_end' => $end,
                'description' => '1.5% AUM management fee (quarterly)',
            ]);
        }
        // No performance fees yet — return under hurdle.

        // Portal documents — sample set across all four categories.
        // Using example.com URLs since real S3/storage isn't wired up.
        PortalDocument::query()
            ->where(function ($q) use ($fund, $investor) {
                $q->where('fund_id', $fund->id)->orWhere('investor_id', $investor->id);
            })
            ->delete();

        $sampleDocs = [
            ['fund', $fund->id, null, 'legal', 'Operating Agreement', 'APDIF-I Operating Agreement v2.pdf', '2024-01-01'],
            ['fund', $fund->id, null, 'legal', 'Membership Interest Purchase Agreement', 'APDIF-I MIPA.pdf', '2024-01-01'],
            ['fund', $fund->id, null, 'legal', 'Private Placement Memorandum', 'APDIF-I PPM.pdf', '2024-01-01'],
            ['fund', $fund->id, null, 'operational', 'Fund Overview', 'APDIF-I Fund Overview 2026 Q1.pdf', '2026-01-15'],
            ['fund', $fund->id, null, 'financial', 'How to Transfer Funds', 'How to Transfer Funds.pdf', '2024-01-01'],
            ['investor', null, $investor->id, 'tax', 'K-1 (2024)', 'inv-1019-K1-2024.pdf', '2025-03-15'],
            ['investor', null, $investor->id, 'tax', 'K-1 (2025)', 'inv-1019-K1-2025.pdf', '2026-03-15'],
        ];

        foreach ($sampleDocs as [$scope, $fundId, $investorId, $category, $subcategory, $title, $datedAt]) {
            PortalDocument::create([
                'scope' => $scope,
                'fund_id' => $fundId,
                'investor_id' => $investorId,
                'category' => $category,
                'subcategory' => $subcategory,
                'title' => $title,
                'file_url' => 'https://example.com/docs/placeholder.pdf',
                'file_size_bytes' => rand(200000, 1500000),
                'mime_type' => 'application/pdf',
                'document_dated_at' => Carbon::parse($datedAt),
            ]);
        }

        $this->command->info(sprintf(
            'Portal demo seeded: fund #%d, holding #%d (%s units), %d distributions, %d fees, %d documents.',
            $fund->id,
            $holding->id,
            $holding->units,
            count($distributions),
            count($quarters),
            count($sampleDocs),
        ));
    }
}
