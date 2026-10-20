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

        // A throwaway login for local development only, never on a deployed server.
        if (app()->environment('local', 'testing')) {
            User::updateOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'email_verified_at' => now(), 'password' => Hash::make('password')]
            );
        }

        // Admin login. Credentials come from ADMIN_EMAIL / ADMIN_PASSWORD (read via
        // config/admin.php so they still work when the config is cached). There is no
        // default password outside local: this repo is shared, so a default would be public.
        $email = config('admin.email');
        $password = config('admin.password');

        if (!$email || !$password) {
            if (app()->environment('local', 'testing')) {
                [$email, $password] = ['admin@ntvvault.test', 'password'];
            } else {
                $this->command?->warn('ADMIN_EMAIL / ADMIN_PASSWORD are not set: no admin account was created or changed.');
            }
        }

        if ($email && $password) {
            User::updateOrCreate(
                ['email' => $email],
                ['name' => 'Admin', 'email_verified_at' => now(), 'password' => Hash::make($password), 'is_admin' => true]
            );
        }

        $this->call(PlanSeeder::class);
        $this->call(ShowsSeeder::class);
    }
}