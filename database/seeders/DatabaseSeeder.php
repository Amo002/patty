<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * `php artisan migrate:fresh --seed` gives the realistic demo restaurant (D-036, D-037).
 * The work lives in DemoSeeder so the demo endpoints and artisan commands can call it too.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
