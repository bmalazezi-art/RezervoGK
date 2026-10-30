<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goalkeeper_profiles', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
        });
        Schema::create('goalkeeper_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goalkeeper_profile_id')->constrained()->cascadeOnDelete();
            $table->date('available_on')->index();
            $table->unique(['goalkeeper_profile_id', 'available_on'], 'keeper_available_date_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goalkeeper_availabilities');
        Schema::table('goalkeeper_profiles', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
