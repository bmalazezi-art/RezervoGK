<?php

use Database\Seeders\MarketplaceCitySeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(MarketplaceCitySeeder::class)->run();
    }

    /**
     * Location rows are retained on rollback because profiles may reference them.
     */
    public function down(): void
    {
        // Intentionally preserve reference data and any dependent profile records.
    }
};
