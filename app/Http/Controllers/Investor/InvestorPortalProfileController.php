<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The investor-portal Profile view. Returns a clearly-split editable vs
 * read-only payload so the frontend can render which fields show a pencil
 * icon.
 *
 * Editable: name, phone, address fields, communication preferences.
 * Read-only (admin-changes only): email, investorType, accreditationStatus,
 * taxIdLast4, residency.
 */
class InvestorPortalProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->shape($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $investor = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'addressLine1' => ['sometimes', 'string', 'max:255'],
            'addressLine2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:100'],
            'stateProvince' => ['sometimes', 'string', 'max:100'],
            'zipPostalCode' => ['sometimes', 'string', 'max:20'],
            'country' => ['sometimes', 'string', 'max:100'],
        ]);

        $columnMap = [
            'name' => 'name',
            'phone' => 'phone',
            'addressLine1' => 'address_line1',
            'addressLine2' => 'address_line2',
            'city' => 'address_city',
            'stateProvince' => 'address_state',
            'zipPostalCode' => 'address_postal_code',
            'country' => 'address_country',
        ];

        $updates = [];
        foreach ($validated as $key => $value) {
            $updates[$columnMap[$key]] = $value;
        }

        if (! empty($updates)) {
            $investor->update($updates);
            // Top-level country mirrors the mailing country.
            if (isset($updates['address_country'])) {
                $investor->update(['country' => $updates['address_country']]);
            }
        }

        return response()->json($this->shape($investor->fresh()));
    }

    private function shape(Investor $investor): array
    {
        return [
            'editable' => [
                'name' => $investor->name,
                'phone' => $investor->phone,
                'addressLine1' => $investor->address_line1,
                'addressLine2' => $investor->address_line2,
                'city' => $investor->address_city,
                'stateProvince' => $investor->address_state,
                'zipPostalCode' => $investor->address_postal_code,
                'country' => $investor->address_country,
            ],
            'readonly' => [
                'code' => $investor->code,
                'email' => $investor->email,
                'investorType' => $investor->personal_investor_type,
                'entityName' => $investor->personal_entity_name,
                'accreditationStatus' => $investor->accreditation_status,
                'taxIdLast4' => $investor->personal_tax_id_last4,
                'residency' => $investor->personal_residency,
                'joinedAt' => optional($investor->joined_at)->toIso8601String(),
            ],
        ];
    }
}
