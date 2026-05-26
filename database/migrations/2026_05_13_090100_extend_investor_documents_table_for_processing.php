<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_documents', function (Blueprint $table) {
            $table->foreignId('investor_profile_id')
                ->nullable()
                ->after('id')
                ->constrained('investors')
                ->cascadeOnDelete();
            $table->string('source')->nullable()->after('type');
            $table->string('provider')->nullable()->after('source');
            $table->string('file_url')->nullable()->after('status');
            $table->string('external_reference_id')->nullable()->after('file_url');
            $table->unsignedBigInteger('uploaded_by')->nullable()->after('external_reference_id');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('uploaded_by');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
        });

        DB::table('investor_documents')
            ->whereNull('investor_profile_id')
            ->update([
                'investor_profile_id' => DB::raw('investor_id'),
                'source' => DB::raw("COALESCE(source, 'manual_upload')"),
                'provider' => DB::raw("COALESCE(provider, 'internal')"),
                'file_url' => DB::raw("COALESCE(file_url, file_name)"),
            ]);
    }

    public function down(): void
    {
        Schema::table('investor_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investor_profile_id');
            $table->dropColumn([
                'source',
                'provider',
                'file_url',
                'external_reference_id',
                'uploaded_by',
                'reviewed_by',
                'reviewed_at',
                'rejection_reason',
            ]);
        });
    }
};
