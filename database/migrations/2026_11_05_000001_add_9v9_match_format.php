<?php

use App\Models\MatchFormat;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MatchFormat::firstOrCreate(['code' => '9v9']);
    }

    public function down(): void
    {
        // Match formats are reference data and are intentionally retained on rollback.
    }
};
