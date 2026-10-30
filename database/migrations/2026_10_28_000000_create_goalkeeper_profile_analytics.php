<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goalkeeper_profile_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goalkeeper_profile_id')->constrained()->cascadeOnDelete();
            $table->date('event_date');
            $table->string('event_type', 16);
            $table->unsignedBigInteger('event_count')->default(0);
            $table->timestamps();
            $table->unique(['goalkeeper_profile_id', 'event_date', 'event_type'], 'keeper_analytics_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goalkeeper_profile_analytics');
    }
};
