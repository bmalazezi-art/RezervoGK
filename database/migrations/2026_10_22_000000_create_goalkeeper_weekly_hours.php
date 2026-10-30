<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goalkeeper_weekly_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goalkeeper_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // ISO weekday: Monday = 1, Sunday = 7.
            $table->time('start_time');
            $table->time('end_time'); // Earlier than start means the following day.
            $table->timestamps();
            $table->unique(['goalkeeper_profile_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goalkeeper_weekly_hours');
    }
};
