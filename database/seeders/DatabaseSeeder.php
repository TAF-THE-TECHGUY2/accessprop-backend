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
        User::updateOrCreate(
            ['email' => 'admin@accessproperties.test'],
            [
                'name' => 'Admin',
                'password' => bcrypt('password'),
            ],
        );

        \App\Models\Setting::singleton();

        // Mock data seeders are intentionally not called by default.
        // To load them on demand: php artisan db:seed --class=InvestorSeeder
        //                        php artisan db:seed --class=EmailLogSeeder
    }
}
