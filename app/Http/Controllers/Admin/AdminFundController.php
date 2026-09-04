<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Distribution;
use App\Models\Fund;
use App\Models\FundFee;
use App\Models\FundFeeDeclaration;
use App\Models\FundHolding;
use App\Models\FundTransaction;
use App\Models\FundUnitPrice;
use App\Models\PortalDocument;
use App\Services\FeeAllocator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminFundController extends Controller
{
    public function index(): JsonResponse
    {
        $funds = Fund::query()
            ->withCount('holdings')
            ->with('unitPrices')
            ->get()
            ->map(fn (Fund $f) => $this->summary($f));

        return response()->json(['data' => $funds]);
    }

    public function show(string $code): JsonResponse
    {
        // reorder() is required: the unitPrices() relation already applies
        // orderBy('as_of_date') ascending, and appending orderByDesc only adds a
        // second ORDER BY term. MySQL honours the first, so without reorder()
        // this sorts OLDEST first — and ->first() below then values the whole
        // fund at its inception price. See Fund::currentUnitPrice().
        $fund = Fund::where('code', $code)
            ->withCount('holdings')
            ->with(['unitPrices' => fn ($q) => $q->reorder()->orderByDesc('as_of_date')])
            ->firstOrFail();

        $holdings = FundHolding::where('fund_id', $fund->id)->get();
        $aum = (float) $holdings->sum('amount_invested');
        $latestPrice = $fund->unitPrices->first();
        $aumAtNav = $latestPrice ? (float) $holdings->sum('units') * (float) $latestPrice->price : 0;

        return response()->json([
            'fund' => $this->summary($fund),
            'aumAtCost' => round($aum, 2),
            'aumAtNav' => round($aumAtNav, 2),
            'holdingsCount' => $holdings->count(),
            'unitPrices' => $fund->unitPrices->map(fn ($p) => [
                'id' => $p->id,
                'date' => $p->as_of_date->toDateString(),
                'quarter' => $p->quarter_label,
                'price' => (float) $p->price,
            ]),
            'recentDistributions' => Distribution::query()
                ->where('fund_id', $fund->id)
                ->orderByDesc('paid_at')
                ->limit(20)
                ->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'paidAt' => optional($d->paid_at)->toDateString(),
                    'amount' => (float) $d->amount,
                    'type' => $d->distribution_type,
                    'investorId' => $d->investor_id,
                ]),
            'recentFees' => FundFee::query()
                ->where('fund_id', $fund->id)
                ->orderByDesc('period_end')
                ->limit(20)
                ->get()
                ->map(fn ($f) => [
                    'id' => $f->id,
                    'feeType' => $f->fee_type,
                    'amount' => (float) $f->amount,
                    'periodStart' => $f->period_start->toDateString(),
                    'periodEnd' => $f->period_end->toDateString(),
                    'investorId' => $f->investor_id,
                ]),
            'documents' => PortalDocument::query()
                ->where('scope', 'fund')
                ->where('fund_id', $fund->id)
                ->orderByDesc('document_dated_at')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'category' => $document->category,
                    'subcategory' => $document->subcategory,
                    'sizeBytes' => $document->file_size_bytes,
                    'mimeType' => $document->mime_type,
                    'documentDatedAt' => optional($document->document_dated_at)->toDateString(),
                    'createdAt' => optional($document->created_at)->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:funds,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'investmentFocus' => ['nullable', 'string', 'max:255'],
            'market' => ['nullable', 'string', 'max:255'],
            'fundType' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,closed,winding_down'],
            'inceptionDate' => ['nullable', 'date'],
            'targetYield' => ['nullable', 'string', 'max:50'],
            'minimumInvestment' => ['nullable', 'numeric', 'min:0'],
        ]);

        $fund = Fund::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'tagline' => $data['tagline'] ?? null,
            'investment_focus' => $data['investmentFocus'] ?? null,
            'market' => $data['market'] ?? null,
            'fund_type' => $data['fundType'] ?? 'Diversified Income',
            'status' => $data['status'] ?? 'active',
            'inception_date' => $data['inceptionDate'] ?? null,
            'target_yield' => $data['targetYield'] ?? null,
            'minimum_investment' => $data['minimumInvestment'] ?? null,
        ]);

        return response()->json($this->summary($fund), 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $fund = Fund::where('code', $code)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'investmentFocus' => ['sometimes', 'nullable', 'string', 'max:255'],
            'market' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fundType' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,closed,winding_down'],
            'inceptionDate' => ['sometimes', 'nullable', 'date'],
            'targetYield' => ['sometimes', 'nullable', 'string', 'max:50'],
            'minimumInvestment' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $map = [
            'name' => 'name',
            'description' => 'description',
            'tagline' => 'tagline',
            'investmentFocus' => 'investment_focus',
            'market' => 'market',
            'fundType' => 'fund_type',
            'status' => 'status',
            'inceptionDate' => 'inception_date',
            'targetYield' => 'target_yield',
            'minimumInvestment' => 'minimum_investment',
        ];

        $updates = [];
        foreach ($data as $k => $v) {
            $updates[$map[$k]] = $v;
        }
        if (! empty($updates)) {
            $fund->update($updates);
        }

        return response()->json($this->summary($fund->fresh()));
    }

    // ----- Unit prices -----

    public function storeUnitPrice(Request $request, string $code): JsonResponse
    {
        $fund = Fund::where('code', $code)->firstOrFail();

        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'quarter' => ['nullable', 'string', 'max:16'],
        ]);

        $price = FundUnitPrice::updateOrCreate(
            ['fund_id' => $fund->id, 'as_of_date' => $data['date']],
            [
                'price' => $data['price'],
                'quarter_label' => $data['quarter'] ?? $this->autoQuarter($data['date']),
            ],
        );

        return response()->json([
            'id' => $price->id,
            'date' => $price->as_of_date->toDateString(),
            'quarter' => $price->quarter_label,
            'price' => (float) $price->price,
        ], 201);
    }

    public function destroyUnitPrice(int $id): JsonResponse
    {
        FundUnitPrice::findOrFail($id)->delete();

        return response()->json(['message' => 'deleted']);
    }

    /**
     * Delete a fund and all of its children (holdings, unit prices,
     * distributions, fees, portal documents). Cascades via FK constraints.
     *
     * Requires `confirm` parameter to match the fund code exactly — guard
     * against accidental destructive calls.
     */
    public function destroy(Request $request, string $code): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'string'],
        ]);

        if ($request->input('confirm') !== $code) {
            return response()->json([
                'message' => 'Confirmation code does not match.',
            ], 422);
        }

        $fund = Fund::where('code', $code)->firstOrFail();

        $impact = [
            'holdings' => $fund->holdings()->count(),
            'unitPrices' => $fund->unitPrices()->count(),
            'distributions' => Distribution::where('fund_id', $fund->id)->count(),
            'fees' => FundFee::where('fund_id', $fund->id)->count(),
            'documents' => PortalDocument::where('fund_id', $fund->id)->count(),
        ];

        PortalDocument::query()
            ->where('fund_id', $fund->id)
            ->pluck('file_url')
            ->reject(fn (string $path) => Str::startsWith($path, ['http://', 'https://']))
            ->each(fn (string $path) => Storage::disk('local')->delete($path));

        $fund->delete();

        return response()->json([
            'message' => 'Fund deleted.',
            'code' => $code,
            'cascadeImpact' => $impact,
        ]);
    }

    // ----- Distributions (auto-allocated across all holdings) -----

    public function declareDistribution(Request $request, string $code): JsonResponse
    {
        $fund = Fund::where('code', $code)->firstOrFail();

        $data = $request->validate([
            'amountPerUnit' => ['required', 'numeric', 'min:0.000001'],
            'paidAt' => ['required', 'date'],
            'distributionType' => ['nullable', 'string', 'in:income,return_of_capital'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = DB::transaction(function () use ($fund, $data) {
            $holdings = FundHolding::where('fund_id', $fund->id)->get();
            $created = 0;
            $totalAmount = 0;

            foreach ($holdings as $h) {
                if ((float) $h->units <= 0) {
                    continue;
                }
                $amount = round((float) $h->units * (float) $data['amountPerUnit'], 2);

                Distribution::create([
                    'fund_id' => $fund->id,
                    'investor_id' => $h->investor_id,
                    'amount' => $amount,
                    'paid_at' => $data['paidAt'],
                    'distribution_type' => $data['distributionType'] ?? 'income',
                    'notes' => $data['notes'] ?? sprintf('Per-unit rate $%.6f', $data['amountPerUnit']),
                ]);

                $created++;
                $totalAmount += $amount;
            }

            return ['count' => $created, 'total' => $totalAmount];
        });

        return response()->json([
            'message' => sprintf(
                'Created %d distributions totaling $%s.',
                $result['count'],
                number_format($result['total'], 2),
            ),
            'count' => $result['count'],
            'totalAmount' => round($result['total'], 2),
        ], 201);
    }

    public function destroyDistribution(int $id): JsonResponse
    {
        Distribution::findOrFail($id)->delete();

        return response()->json(['message' => 'deleted']);
    }

    // ----- Fees (auto-allocated across all holdings) -----

    /**
     * Records the accountant's quarterly fee and allocates it.
     *
     * The website does not calculate this fee. The accountant computes it from
     * the fund's gross asset value after quarter end, and that figure is
     * entered here as a total. All this does is divide it pro rata by ownership
     * for the quarter, so the sum of what investors see always equals the
     * number in the books.
     *
     * Re-declaring the same fund, type and period replaces the previous
     * allocations rather than adding to them — a hand-entered figure gets
     * corrected, and a correction must not double-charge the quarter.
     */
    public function declareFee(Request $request, string $code): JsonResponse
    {
        $fund = Fund::where('code', $code)->firstOrFail();

        $data = $request->validate([
            'feeType' => ['required', 'string', 'in:aum,performance'],
            'totalAmount' => ['required', 'numeric', 'min:0'],
            'grossAssetValue' => ['nullable', 'numeric', 'min:0'],
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $start = Carbon::parse($data['periodStart'])->startOfDay();
        $end = Carbon::parse($data['periodEnd'])->startOfDay();

        $allocator = new FeeAllocator();

        $transactions = FundTransaction::query()
            ->where('fund_id', $fund->id)
            ->whereDate('transaction_date', '<=', $end)
            ->orderBy('transaction_date')
            ->get();

        $weights = $allocator->timeWeightedUnits($transactions, $start, $end);

        if ($weights === []) {
            return response()->json([
                'message' => sprintf(
                    'Nobody held %s between %s and %s, so there is nothing to allocate this fee to.',
                    $fund->code,
                    $start->toDateString(),
                    $end->toDateString(),
                ),
            ], 422);
        }

        $amounts = $allocator->allocate((float) $data['totalAmount'], $weights);
        $percentages = $allocator->ownershipPercentages($weights);

        $declaration = DB::transaction(function () use ($fund, $data, $start, $end, $amounts, $percentages, $request) {
            // Matched on the date part rather than through updateOrCreate: the
            // date cast writes a time component, so an equality match on
            // toDateString() misses the existing row and the insert then
            // collides with the unique key.
            $attributes = [
                'total_amount' => $data['totalAmount'],
                'gross_asset_value' => $data['grossAssetValue'] ?? null,
                'basis' => 'time_weighted_units',
                'notes' => $data['description'] ?? null,
                'declared_by' => $request->user()?->id,
            ];

            $declaration = FundFeeDeclaration::query()
                ->where('fund_id', $fund->id)
                ->where('fee_type', $data['feeType'])
                ->whereDate('period_start', $start)
                ->whereDate('period_end', $end)
                ->first();

            if ($declaration) {
                $declaration->update($attributes);
            } else {
                $declaration = FundFeeDeclaration::create($attributes + [
                    'fund_id' => $fund->id,
                    'fee_type' => $data['feeType'],
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                ]);
            }

            // Replace, never append: this is a correction path as much as a
            // creation path.
            $declaration->allocations()->delete();

            foreach ($amounts as $investorId => $amount) {
                FundFee::create([
                    'fee_declaration_id' => $declaration->id,
                    'fund_id' => $fund->id,
                    'investor_id' => $investorId,
                    'fee_type' => $data['feeType'],
                    'amount' => $amount,
                    'ownership_pct' => $percentages[$investorId] ?? null,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'description' => $data['description'] ?? sprintf(
                        '%s fee for %s – %s, allocated by ownership',
                        ucfirst($data['feeType']),
                        $start->toDateString(),
                        $end->toDateString(),
                    ),
                ]);
            }

            return $declaration;
        });

        $reconciles = $declaration->allocationsReconcile();

        return response()->json([
            'message' => sprintf(
                'Allocated $%s across %d investor(s) for %s – %s.',
                number_format((float) $data['totalAmount'], 2),
                count($amounts),
                $start->toDateString(),
                $end->toDateString(),
            ),
            'declarationId' => $declaration->id,
            'totalAmount' => round((float) $data['totalAmount'], 2),
            'allocatedTotal' => round(array_sum($amounts), 2),
            'reconciles' => $reconciles,
            'count' => count($amounts),
            'basis' => 'time_weighted_units',
        ], $reconciles ? 201 : 500);
    }

    public function destroyFee(int $id): JsonResponse
    {
        FundFee::findOrFail($id)->delete();

        return response()->json(['message' => 'deleted']);
    }

    private function summary(Fund $fund): array
    {
        $latest = $fund->unitPrices->sortByDesc('as_of_date')->first();

        return [
            'code' => $fund->code,
            'name' => $fund->name,
            'description' => $fund->description,
            'tagline' => $fund->tagline,
            'investmentFocus' => $fund->investment_focus,
            'market' => $fund->market,
            'fundType' => $fund->fund_type,
            'status' => $fund->status,
            'inceptionDate' => optional($fund->inception_date)->toDateString(),
            'targetYield' => $fund->target_yield,
            'minimumInvestment' => $fund->minimum_investment ? (float) $fund->minimum_investment : null,
            'holdingsCount' => $fund->holdings_count ?? $fund->holdings()->count(),
            'currentUnitPrice' => $latest ? (float) $latest->price : null,
            'currentUnitPriceDate' => $latest ? $latest->as_of_date->toDateString() : null,
        ];
    }

    private function autoQuarter(string $date): string
    {
        $c = Carbon::parse($date);

        return 'Q'.$c->quarter.' '.$c->year;
    }

    /**
     * Price and unit count an investment would receive on a given date.
     *
     * Lets the admin sanity-check the arithmetic before committing an entry —
     * a wrong date silently produces a wrong unit count, and a wrong unit count
     * is invisible until someone reconciles the position months later.
     */
    public function pricePreview(Request $request, string $code): JsonResponse
    {
        $fund = Fund::where('code', $code)->firstOrFail();

        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'units' => ['nullable', 'numeric', 'gt:0'],
            'unitPriceOverride' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        $row = $fund->bookValueAsOf($data['date']);
        $bookValue = $row ? (float) $row->price : null;
        $premiumPct = (float) $fund->current_premium_pct;

        // Precedence mirrors the write path: a supplied unit count wins, then an
        // explicit price, then the published book value plus premium.
        $source = 'book value';
        $pricePerUnit = null;
        $units = isset($data['units']) ? (float) $data['units'] : null;

        if ($units !== null && $amount !== null) {
            $pricePerUnit = round($amount / $units, 8);
            $source = 'derived from units';
        } elseif (isset($data['unitPriceOverride'])) {
            $pricePerUnit = round((float) $data['unitPriceOverride'], 8);
            $source = 'manual price';
        } elseif ($bookValue !== null && $bookValue > 0) {
            $pricePerUnit = round($bookValue * (1 + ($premiumPct / 100)), 8);
        }

        if ($pricePerUnit === null) {
            return response()->json([
                'message' => sprintf(
                    'No unit price is published on or before %s (earliest is %s). Supply a unit count or a price.',
                    Carbon::parse($data['date'])->toDateString(),
                    optional($fund->unitPrices()->reorder()->orderBy('as_of_date')->first())->as_of_date?->toDateString() ?? 'none',
                ),
            ], 422);
        }

        return response()->json([
            'bookValue' => $bookValue !== null ? round($bookValue, 8) : null,
            'bookValueAsOf' => $row?->as_of_date->toDateString(),
            'quarterLabel' => $row?->quarter_label,
            'premiumPct' => $premiumPct,
            'pricePerUnit' => $pricePerUnit,
            'priceSource' => $source,
            'priceOverridden' => $source !== 'book value',
            'amount' => $amount,
            'units' => $units ?? ($amount !== null ? round($amount / $pricePerUnit, 6) : null),
        ]);
    }

}
