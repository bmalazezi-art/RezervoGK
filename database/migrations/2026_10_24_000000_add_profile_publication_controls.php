<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goalkeeper_profiles', function (Blueprint $table) {
            // Completed registration publishes by default. Ownership still hides all unclaimed demos.
            // These are moderation fields, never owner mass-assignable.
            $table->boolean('is_published')->default(true);
            $table->boolean('is_suspended')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('goalkeeper_profiles', function (Blueprint $table) {
            $table->dropColumn(['is_published', 'is_suspended']);
        });
    }
};
