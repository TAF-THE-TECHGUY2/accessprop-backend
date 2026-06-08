<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Distribution;
use App\Models\Fund;
use App\Models\FundFee;
use App\Models\FundHolding;
use App\Models\FundUnitPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $fund = Fund::where('code', $code)
            ->withCount('holdings')
            ->with(['unitPrices' => fn ($q) => $q->orderByDesc('as_of_date')])
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
                ->whereHas('fundHolding', fn ($q) => $q->where('fund_id', $fund->id))
                ->orderByDesc('paid_at')
                ->limit(20)
                ->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'paidAt' => optional($d->paid_at)->toDateString(),
                    'amount' => (float) $d->amount,
                    'type' => $d->distribution_type,
                    'holdingId' => $d->fund_holding_id,
                ]),
            'recentFees' => FundFee::query()
                ->whereHas('fundHolding', fn ($q) => $q->where('fund_id', $fund->id))
                ->orderByDesc('period_end')
                ->limit(20)
                ->get()
                ->map(fn ($f) => [
                    'id' => $f->id,
                    'feeType' => $f->fee_type,
                    'amount' => (float) $f->amount,
                    'periodStart' => $f->period_start->toDateString(),
                    'periodEnd' => $f->period_end->toDateString(),
                    'holdingId' => $f->fund_holding_id,
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:funds,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
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
            'fundType' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,closed,winding_down'],
            'inceptionDate' => ['sometimes', 'nullable', 'date'],
            'targetYield' => ['sometimes', 'nullable', 'string', 'max:50'],
            'minimumInvestment' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $map = [
            'name' => 'name',
            'description' => 'description',
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
                    'fund_holding_id' => $h->id,
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

    public function declareFee(Request $request, string $code): JsonResponse
    {
        $fund = Fund::where('code', $code)->firstOrFail();

        $data = $request->validate([
            'feeType' => ['required', 'string', 'in:aum,performance'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:1'], // fraction (0.0150 = 1.5%)
            'amount' => ['nullable', 'numeric', 'min:0'],         // flat $ per holding
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if (empty($data['rate']) && empty($data['amount'])) {
            return response()->json([
                'message' => 'Either rate or amount must be provided.',
            ], 422);
        }

        $result = DB::transaction(function () use ($fund, $data) {
            $holdings = FundHolding::where('fund_id', $fund->id)->get();
            $created = 0;
            $totalAmount = 0;

            foreach ($holdings as $h) {
                $amount = isset($data['rate'])
                    ? round((float) $h->amount_invested * (float) $data['rate'], 2)
                    : (float) $data['amount'];

                if ($amount <= 0) {
                    continue;
                }

                FundFee::create([
                    'fund_holding_id' => $h->id,
                    'fee_type' => $data['feeType'],
                    'amount' => $amount,
                    'period_start' => $data['periodStart'],
                    'period_end' => $data['periodEnd'],
                    'description' => $data['description'] ?? sprintf(
                        '%s fee (%s)',
                        ucfirst($data['feeType']),
                        isset($data['rate']) ? number_format($data['rate'] * 100, 2).'%' : '$'.number_format($amount, 2),
                    ),
                ]);

                $created++;
                $totalAmount += $amount;
            }

            return ['count' => $created, 'total' => $totalAmount];
        });

        return response()->json([
            'message' => sprintf(
                'Created %d fee entries totaling $%s.',
                $result['count'],
                number_format($result['total'], 2),
            ),
            'count' => $result['count'],
            'totalAmount' => round($result['total'], 2),
        ], 201);
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
        $c = \Carbon\Carbon::parse($date);
        return 'Q'.$c->quarter.' '.$c->year;
    }
}
