<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\InvestorWelcomeMail;
use App\Models\EmailLog;
use App\Models\Fund;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvestorRegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:investors,email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'mobilePhone' => ['nullable', 'string', 'max:50'],
            'addressLine1' => ['required', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'stateProvince' => ['required', 'string', 'max:100'],
            'zipPostalCode' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:100'],
            'experience' => ['required', 'string', 'in:experienced,new'],
            'accreditationStatus' => ['required', 'string', 'in:accredited,not-accredited'],
            'investmentAmount' => [
                'required',
                'numeric',
                // Direct (accredited) path requires $10k min; third-party (non-accredited)
                // path allows $100 min.
                $request->input('accreditationStatus') === 'accredited' ? 'min:10000' : 'min:100',
            ],
            'receiveUpdates' => ['nullable', 'boolean'],
            // Optional today because a single offering is open. Present so the
            // flow does not need reworking when a second fund launches.
            'fundCode' => ['nullable', 'string', 'max:100'],
        ]);

        $accreditation = $data['accreditationStatus'] === 'accredited' ? 'accredited' : 'non_accredited';
        $isUS = $data['country'] === 'United States';
        $fund = $this->resolveOfferingFund($data['fundCode'] ?? null);

        $investor = DB::transaction(function () use ($data, $accreditation, $isUS, $fund) {
            $maxNum = (int) Investor::query()
                ->selectRaw("MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as max_num")
                ->value('max_num');
            $nextNum = max(1000, $maxNum) + 1;
            $code = sprintf('inv-%04d', $nextNum);

            $investor = Investor::create([
                'code' => $code,
                'name' => trim($data['firstName'].' '.$data['lastName']),
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['mobilePhone'] ?? null,
                'country' => $data['country'],
                'joined_at' => now(),
                'investment_amount' => $data['investmentAmount'],
                'accreditation_status' => $accreditation,
                'kyc_status' => 'pending',
                'accreditation_verification_status' => 'not_started',
                'investment_status' => 'awaiting_kyc',
                'dashboard_status' => 'pending',
                'document_signing_status' => 'not_started',
                'address_line1' => $data['addressLine1'],
                'address_line2' => $data['addressLine2'] ?? null,
                'address_city' => $data['city'],
                'address_state' => $data['stateProvince'],
                'address_postal_code' => $data['zipPostalCode'],
                'address_country' => $data['country'],
                'personal_investor_type' => 'Individual',
                'personal_entity_name' => null,
                'personal_tax_id_last4' => null,
                'personal_residency' => $isUS ? 'U.S. Person' : 'Non-U.S. Person',
                'experience' => $data['experience'],
                // fund_id is authoritative. investment_fund_name is a
                // denormalised copy kept in sync for the read paths that still
                // match on it; it must never be the source of truth again.
                'fund_id' => $fund->id,
                'investment_fund_name' => $fund->name,
                'investment_commitment' => $data['investmentAmount'],
                'investment_funded' => 0,
                'investment_wallet_status' => 'KYC required',
                'investment_expected_yield' => $fund->target_yield ?? '8.0% target',
                'investment_last_distribution' => null,
            ]);

            $investor->activities()->create([
                'code' => 'act-'.$code.'-'.now()->timestamp,
                'title' => 'Investor created account',
                'description' => 'Onboarding flow completed; awaiting compliance review.',
                'occurred_at' => now(),
            ]);

            return $investor;
        });

        $this->sendWelcomeEmail($investor);

        $token = $investor->createToken('onboarding-session', ['investor'])->plainTextToken;

        return response()->json([
            'code' => $investor->code,
            'name' => $investor->name,
            'kycStatus' => $investor->kyc_status,
            'accreditationStatus' => $investor->accreditation_status,
            'token' => $token,
        ], 201);
    }

    /**
     * The fund this registration subscribes to.
     *
     * Registration previously stored only a hardcoded fund name string. When
     * that string drifted from funds.name — as it did in production — the
     * investor had no resolvable fund and hit a hard failure at funding time.
     * The FK is now set at registration so the link can never be ambiguous.
     *
     * Fails the registration rather than guessing. An investor created without
     * a fund cannot fund, so a clear error here beats a broken account later.
     *
     * @throws ValidationException
     */
    private function resolveOfferingFund(?string $fundCode): Fund
    {
        if ($fundCode !== null && $fundCode !== '') {
            $fund = Fund::where('code', $fundCode)->first();

            if (! $fund) {
                throw ValidationException::withMessages([
                    'fundCode' => "No fund exists with code '{$fundCode}'.",
                ]);
            }

            return $fund;
        }

        $active = Fund::where('status', 'active')->get();

        if ($active->count() === 1) {
            return $active->first();
        }

        throw ValidationException::withMessages([
            'fundCode' => $active->isEmpty()
                ? 'No active fund is currently open for investment.'
                : 'More than one fund is open; a fundCode is required to register.',
        ]);
    }

    private function sendWelcomeEmail(Investor $investor): void
    {
        $subject = 'Welcome to Access Properties';
        $status = 'sent';

        try {
            Mail::to($investor->email)->send(new InvestorWelcomeMail($investor));
        } catch (Throwable $e) {
            $status = 'failed';
            Log::warning('Investor welcome email failed', [
                'investor_code' => $investor->code,
                'email' => $investor->email,
                'error' => $e->getMessage(),
            ]);
        }

        EmailLog::create([
            'code' => 'eml-'.$investor->code.'-welcome-'.now()->timestamp,
            'recipient' => $investor->email,
            'type' => 'investor_welcome',
            'subject' => $subject,
            'status' => $status,
            'sent_at' => now(),
        ]);
    }
}
