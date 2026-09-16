<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Not User::factory() — that pulls in fakerphp/faker, a dev-only dependency
        // not installed in production (`composer install --no-dev`).
        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ]
        );

        // Admin login for the deployed environment. Credentials come from env vars
        // (set in Laravel Cloud's dashboard) rather than being hardcoded here, since
        // this seeder runs against real, internet-reachable deployments.
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@ntvvault.com')],
            [
                'name' => 'Admin',
                'email_verified_at' => now(),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'is_admin' => true,
            ]
        );

        $this->call(PlanSeeder::class);
        $this->call(ShowsSeeder::class);
    }
}