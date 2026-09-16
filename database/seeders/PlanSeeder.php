<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['name' => 'Mobile',   'slug' => 'mobile',   'price_ugx' => 9900,  'resolution' => 'SD (480p)',           'max_screens' => 1, 'downloads_allowed' => true, 'is_popular' => false, 'sort_order' => 1],
            ['name' => 'Basic',    'slug' => 'basic',    'price_ugx' => 14900, 'resolution' => 'SD (480p)',           'max_screens' => 1, 'downloads_allowed' => true, 'is_popular' => false, 'sort_order' => 2],
            ['name' => 'Standard', 'slug' => 'standard', 'price_ugx' => 24900, 'resolution' => 'Full HD (1080p)',     'max_screens' => 2, 'downloads_allowed' => true, 'is_popular' => true,  'sort_order' => 3],
            ['name' => 'Premium',  'slug' => 'premium',  'price_ugx' => 34900, 'resolution' => 'Ultra HD (4K) + HDR', 'max_screens' => 4, 'downloads_allowed' => true, 'is_popular' => false, 'sort_order' => 4],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}