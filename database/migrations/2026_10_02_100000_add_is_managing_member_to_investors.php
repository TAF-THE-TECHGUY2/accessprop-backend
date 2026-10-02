<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Managing Members of the fund.
 *
 * A flag beside accreditation_status rather than a third value in it: a
 * managing member is still an accredited investor, and the DocuSign template,
 * onboarding pathway, processing steps and communications audience all key on
 * accreditation_status === 'accredited'. This only changes what the label
 * reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->boolean('is_managing_member')
                ->default(false)
                ->after('accreditation_status');
        });
    }

    public function down(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->dropColumn('is_managing_member');
        });
    }
};
