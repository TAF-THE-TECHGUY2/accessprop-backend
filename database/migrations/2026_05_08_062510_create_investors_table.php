<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investors', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('country');
            $table->timestamp('joined_at');

            $table->decimal('investment_amount', 15, 2)->default(0);

            $table->string('accreditation_status')->index();
            $table->string('kyc_status')->index();
            $table->string('investment_status')->index();
            $table->string('dashboard_status')->index();

            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('address_city');
            $table->string('address_state');
            $table->string('address_postal_code');
            $table->string('address_country');

            $table->string('personal_investor_type');
            $table->string('personal_entity_name')->nullable();
            $table->string('personal_tax_id_last4', 4)->nullable();
            $table->string('personal_residency');

            $table->string('investment_fund_name');
            $table->decimal('investment_commitment', 15, 2)->default(0);
            $table->decimal('investment_funded', 15, 2)->default(0);
            $table->string('investment_wallet_status');
            $table->string('investment_expected_yield');
            $table->timestamp('investment_last_distribution')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investors');
    }
};
