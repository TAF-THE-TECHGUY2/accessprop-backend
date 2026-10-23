<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // A known login for a developer machine only. Run anywhere else this
        // created admin@accessproperties.test / "password" — and, being
        // updateOrCreate, put the password back to "password" on every seed.
        if (app()->environment('local', 'testing')) {
            User::updateOrCreate(
                ['email' => 'admin@accessproperties.test'],
                [
                    'name' => 'Admin',
                    'password' => bcrypt('password'),
                ],
            );
        }

        \App\Models\Setting::singleton();

        // Mock data seeders are intentionally not called by default.
        // To load them on demand: php artisan db:seed --class=InvestorSeeder
        //                        php artisan db:seed --class=EmailLogSeeder
    }
}
