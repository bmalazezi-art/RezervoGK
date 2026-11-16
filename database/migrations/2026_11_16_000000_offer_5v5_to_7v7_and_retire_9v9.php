<?php

use App\Models\MatchFormat;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['5v5', '6v6', '7v7', '11v11'] as $code) {
            MatchFormat::firstOrCreate(['code' => $code]);
        }

        // 9v9 is no longer offered. Deleting it also removes it from any
        // goalkeeper profile that had selected it (cascade on the pivot).
        MatchFormat::where('code', '9v9')->delete();
    }

    public function down(): void
    {
        MatchFormat::firstOrCreate(['code' => '9v9']);
    }
};
