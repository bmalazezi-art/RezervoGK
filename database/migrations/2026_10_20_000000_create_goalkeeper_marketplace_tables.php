<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unique(['country_id', 'name']);
            $table->timestamps();
        });
        Schema::create('match_formats', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->timestamps();
        });
        Schema::create('goalkeeper_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('bio');
            $table->text('photo_url');
            $table->unsignedInteger('price_cents')->index();
            $table->char('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('experience_years')->default(0);
            $table->json('languages');
            // Presentation metadata for the demo; no review/verification workflow yet.
            $table->decimal('rating', 2, 1)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('matches_played')->default(0);
            $table->string('availability_label')->default('Sot');
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });
        Schema::create('goalkeeper_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goalkeeper_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 16); // E.164, including the leading +.
            $table->string('whatsapp', 15); // International digits for wa.me.
            $table->timestamps();
        });
        Schema::create('goalkeeper_profile_match_format', function (Blueprint $table) {
            $table->foreignId('goalkeeper_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_format_id')->constrained()->cascadeOnDelete();
            $table->primary(['goalkeeper_profile_id', 'match_format_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goalkeeper_profile_match_format');
        Schema::dropIfExists('goalkeeper_contacts');
        Schema::dropIfExists('goalkeeper_profiles');
        Schema::dropIfExists('match_formats');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('countries');
    }
};
