<?php

namespace Database\Seeders;

use App\Models\Investor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InvestorSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/fixtures/investors.json');
        $records = json_decode(file_get_contents($path), true);

        foreach ($records as $record) {
            $investor = Investor::updateOrCreate(
                ['code' => $record['id']],
                [
                    'name' => $record['name'],
                    'email' => $record['email'],
                    'password' => Hash::make('password'),
                    'phone' => $record['phone'],
                    'country' => $record['country'],
                    'joined_at' => $record['joinedAt'],
                    'investment_amount' => $record['investmentAmount'],
                    'accreditation_status' => $record['accreditationStatus'],
                    'accreditation_verification_status' => $this->deriveAccreditationVerificationStatus($record),
                    'kyc_status' => $record['kycStatus'],
                    'investment_status' => $record['investmentStatus'],
                    'dashboard_status' => $record['dashboardStatus'],
                    'document_signing_status' => $this->deriveDocumentSigningStatus($record),
                    'address_line1' => $record['address']['line1'],
                    'address_line2' => $record['address']['line2'] ?? null,
                    'address_city' => $record['address']['city'],
                    'address_state' => $record['address']['state'],
                    'address_postal_code' => $record['address']['postalCode'],
                    'address_country' => $record['address']['country'],
                    'personal_investor_type' => $record['personalInfo']['investorType'],
                    'personal_entity_name' => $record['personalInfo']['entityName'] === 'N/A'
                        ? null
                        : $record['personalInfo']['entityName'],
                    'personal_tax_id_last4' => $record['personalInfo']['taxIdLast4'],
                    'personal_residency' => $record['personalInfo']['residency'],
                    'investment_fund_name' => $record['investmentInfo']['fundName'],
                    'investment_commitment' => $record['investmentInfo']['commitment'],
                    'investment_funded' => $record['investmentInfo']['funded'],
                    'investment_wallet_status' => $record['investmentInfo']['walletStatus'],
                    'investment_expected_yield' => $record['investmentInfo']['expectedYield'],
                    'investment_last_distribution' => $record['investmentInfo']['lastDistribution'] ?? null,
                ],
            );

            $investor->documents()->delete();
            foreach ($record['documents'] ?? [] as $doc) {
                $investor->documents()->create([
                    'code' => $doc['id'],
                    'type' => $doc['type'],
                    'source' => 'seeded_fixture',
                    'provider' => 'internal',
                    'file_name' => $doc['fileName'],
                    'file_url' => $doc['fileName'],
                    'submitted_at' => $doc['submittedAt'],
                    'status' => $doc['status'],
                ]);
            }

            $investor->activities()->delete();
            foreach ($record['activity'] ?? [] as $activity) {
                $investor->activities()->create([
                    'code' => $activity['id'],
                    'title' => $activity['title'],
                    'description' => $activity['description'],
                    'occurred_at' => $activity['timestamp'],
                ]);
            }

            $investor->messages()->delete();
            foreach ($record['messages'] ?? [] as $message) {
                $investor->messages()->create([
                    'code' => $message['id'],
                    'subject' => $message['subject'],
                    'preview' => $message['preview'],
                    'sent_at' => $message['sentAt'],
                ]);
            }

            $investor->notes()->delete();
            foreach ($record['notes'] ?? [] as $note) {
                $investor->notes()->create([
                    'code' => $note['id'],
                    'body' => $note['body'],
                    'created_at' => $note['createdAt'],
                    'updated_at' => $note['createdAt'],
                ]);
            }
        }
    }

    private function deriveAccreditationVerificationStatus(array $record): string
    {
        if (($record['accreditationStatus'] ?? null) !== 'accredited') {
            return 'not_started';
        }

        return match ($record['investmentStatus'] ?? null) {
            'awaiting_accreditation_verification' => 'verification_required',
            'awaiting_documents', 'awaiting_legal_approval' => 'verification_submitted',
            'awaiting_funding', 'funds_sent', 'funds_confirmed', 'active' => 'verification_approved',
            'inactive' => ($record['kycStatus'] ?? null) === 'rejected'
                ? 'verification_rejected'
                : 'verification_approved',
            default => 'not_started',
        };
    }

    private function deriveDocumentSigningStatus(array $record): string
    {
        return match ($record['investmentStatus'] ?? null) {
            'awaiting_documents' => 'sent',
            'awaiting_legal_approval' => 'signed',
            'awaiting_funding', 'funds_sent', 'funds_confirmed', 'active' => 'completed',
            default => 'not_started',
        };
    }
}
