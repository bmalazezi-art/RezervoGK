<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daily, anonymous search counts (no IP, user or session stored) so admins see where demand is.
        Schema::create('search_demand', function (Blueprint $table) {
            $table->id();
            $table->date('event_date');
            $table->unsignedBigInteger('country_id')->default(0);
            $table->unsignedBigInteger('city_id')->default(0);
            $table->string('format', 10)->default('');
            $table->unsignedInteger('searches')->default(0);
            $table->unsignedInteger('zero_results')->default(0);
            $table->timestamps();
            $table->unique(['event_date', 'country_id', 'city_id', 'format'], 'search_demand_daily_unique');
        });

        // Set only by an administrator; separate from the legacy demo is_verified flag.
        Schema::table('goalkeeper_profiles', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_demand');
        Schema::table('goalkeeper_profiles', function (Blueprint $table) {
            $table->dropColumn('verified_at');
        });
    }
};
