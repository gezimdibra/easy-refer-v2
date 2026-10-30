<?php

namespace Database\Seeders;

use App\Services\Demo\DemoService;
use Illuminate\Database\Seeder;

/**
 * Seeds Juniper Café, the G6 demo business (Amendment 63):
 * flagged is_demo, configured through the real machinery,
 * never chargeable. Idempotent: the seed refreshes the one
 * flagged business. Run at deploy; the demo surface also
 * self-heals through DemoService::ensureBusiness() if the
 * business is missing.
 */
class JuniperDemoSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoService::class)->ensureBusiness();
    }
}
