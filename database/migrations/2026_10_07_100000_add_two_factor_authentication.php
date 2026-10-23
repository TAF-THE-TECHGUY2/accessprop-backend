<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authenticator-app sign-in (TOTP), for admins and investors alike.
 *
 * The secret and recovery codes are stored encrypted with APP_KEY, so rotating
 * that key would strand every enrolled account — reset them if it ever has to
 * change. `two_factor_confirmed_at` is what "enabled" means: a secret alone is
 * a setup that was started and never finished.
 *
 * `two_factor_last_timestep` remembers the 30-second window of the last code
 * accepted, so a code read over someone's shoulder cannot be replayed inside
 * the same window.
 *
 * Trusted devices back "remember this device for 30 days". Only a hash of the
 * device token is kept, as with Sanctum's own tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'investors'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->text('two_factor_secret')->nullable();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_confirmed_at')->nullable();
                $table->unsignedBigInteger('two_factor_last_timestep')->nullable();
            });
        }

        Schema::create('two_factor_trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->morphs('authenticatable', 'two_factor_devices_owner_index');
            $table->string('token_hash', 64)->unique();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_trusted_devices');

        foreach (['users', 'investors'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn([
                    'two_factor_secret',
                    'two_factor_recovery_codes',
                    'two_factor_confirmed_at',
                    'two_factor_last_timestep',
                ]);
            });
        }
    }
};
