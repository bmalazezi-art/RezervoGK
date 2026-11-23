<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A goalkeeper keeps one primary (home) city on goalkeeper_profiles.city_id and may add more cities in the
 * same country. Each weekly day and each extra date says which of those cities it is in; existing rows are
 * backfilled to the profile's primary city, and a missing city is always read as the primary city.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goalkeeper_profile_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goalkeeper_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['goalkeeper_profile_id', 'city_id'], 'keeper_city_unique');
            $table->index('city_id');
        });

        Schema::table('goalkeeper_weekly_hours', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('weekday')->constrained()->nullOnDelete();
        });
        Schema::table('goalkeeper_availabilities', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('available_on')->constrained()->nullOnDelete();
        });

        foreach (['goalkeeper_weekly_hours', 'goalkeeper_availabilities'] as $table) {
            DB::table($table)->whereNull('city_id')->update([
                'city_id' => DB::table('goalkeeper_profiles')->select('city_id')
                    ->whereColumn('goalkeeper_profiles.id', $table.'.goalkeeper_profile_id')->limit(1),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['goalkeeper_availabilities', 'goalkeeper_weekly_hours'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('city_id');
            });
        }
        Schema::dropIfExists('goalkeeper_profile_cities');
    }
};
