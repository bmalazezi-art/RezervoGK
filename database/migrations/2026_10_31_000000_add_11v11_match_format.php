<?php

use App\Models\MatchFormat;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MatchFormat::firstOrCreate(['code' => '11v11']);
    }

    /**
     * Keep the format as reference data on rollback, consistent with marketplace cities.
     */
    public function down(): void
    {
        // Intentionally preserve the format if profiles reference it.
    }
};
