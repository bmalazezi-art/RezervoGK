<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goalkeeper_availability_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goalkeeper_profile_id')->constrained()->cascadeOnDelete();
            $table->date('unavailable_on')->index();
            $table->timestamps();
            $table->unique(['goalkeeper_profile_id', 'unavailable_on'], 'keeper_unavailable_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goalkeeper_availability_exceptions');
    }
};
