<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goalkeeper_profile_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goalkeeper_profile_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 40);
            $table->text('details')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->timestamps();
            $table->index(['goalkeeper_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goalkeeper_profile_reports');
    }
};
