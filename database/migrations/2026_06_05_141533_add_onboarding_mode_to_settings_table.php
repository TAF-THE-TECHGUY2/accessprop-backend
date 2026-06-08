<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // false (default): steps unlock one at a time after the previous completes.
            // true: investor can work on any step in parallel — useful for ops shortcuts
            //       and for self-serve flows where Persona / InvestReady / DocuSign can run concurrently.
            $table->boolean('allow_parallel_onboarding')->default(false)->after('auto_activate_dashboard');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('allow_parallel_onboarding');
        });
    }
};
