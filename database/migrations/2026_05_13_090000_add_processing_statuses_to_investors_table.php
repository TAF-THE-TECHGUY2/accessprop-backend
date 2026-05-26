<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->string('accreditation_verification_status')
                ->default('not_started')
                ->after('accreditation_status')
                ->index();
            $table->string('document_signing_status')
                ->default('not_started')
                ->after('dashboard_status')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->dropIndex(['accreditation_verification_status']);
            $table->dropIndex(['document_signing_status']);
            $table->dropColumn([
                'accreditation_verification_status',
                'document_signing_status',
            ]);
        });
    }
};
