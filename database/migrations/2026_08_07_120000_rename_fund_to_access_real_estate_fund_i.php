<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the fund to match the new four-entity legal structure.
 *
 * The company split from one entity into four: Access Properties (platform),
 * Access Real Estate Fund I (the fund), Access Investment Management (adviser)
 * and Access Property Advisors (construction). The fund record still carried the
 * pre-split name, so the portal showed investors a fund name that no longer
 * exists on any legal document.
 *
 *   apdif-1 / "Access Properties Diversified Income Fund I"
 *     -> aref-i / "Access Real Estate Fund I"
 *
 * Three tables move together, and they must move together:
 *
 *  1. funds.name / funds.code — the rename itself.
 *
 *  2. investors.investment_fund_name — a denormalised copy of funds.name.
 *     InvestorPortalDocumentsController::accessibleFundIds() resolves document
 *     access by string-matching this column against funds.name. Renaming the
 *     fund alone would break that match for every investor and silently revoke
 *     their access to fund documents. This is the reason the migration is
 *     wrapped in a transaction rather than run as three loose statements.
 *
 *  3. communications.audience — fund-scoped rows store the literal tag
 *     "fund:{code}". InvestorPortalCommunicationsController rebuilds that tag
 *     from the live code on every request, so a stale tag makes the
 *     communication invisible in index() and a 403 in show().
 *
 * Matching is by the old code/name rather than by id, so re-running against a
 * database that has already been renamed is a no-op instead of a corruption.
 */
return new class extends Migration
{
    private const OLD_CODE = 'apdif-1';

    private const NEW_CODE = 'aref-i';

    private const OLD_NAME = 'Access Properties Diversified Income Fund I';

    private const NEW_NAME = 'Access Real Estate Fund I';

    public function up(): void
    {
        $this->rename(self::OLD_CODE, self::NEW_CODE, self::OLD_NAME, self::NEW_NAME);
    }

    public function down(): void
    {
        $this->rename(self::NEW_CODE, self::OLD_CODE, self::NEW_NAME, self::OLD_NAME);
    }

    private function rename(string $fromCode, string $toCode, string $fromName, string $toName): void
    {
        DB::transaction(function () use ($fromCode, $toCode, $fromName, $toName) {
            $funds = DB::table('funds')
                ->where('code', $fromCode)
                ->update(['code' => $toCode, 'name' => $toName, 'updated_at' => now()]);

            // Denormalised copy that gates document access. Keyed off the name
            // rather than fund_id because that is exactly how the read path
            // matches, so anything the read path would find, this updates.
            $investors = DB::table('investors')
                ->where('investment_fund_name', $fromName)
                ->update(['investment_fund_name' => $toName, 'updated_at' => now()]);

            $communications = DB::table('communications')
                ->where('audience', 'fund:'.$fromCode)
                ->update(['audience' => 'fund:'.$toCode, 'updated_at' => now()]);

            echo sprintf(
                "  renamed %s -> %s: %d fund, %d investors, %d communications\n",
                $fromCode,
                $toCode,
                $funds,
                $investors,
                $communications,
            );
        });
    }
};
