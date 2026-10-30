<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goalkeeper_profiles', function (Blueprint $table) {
            $table->boolean('admin_hidden')->default(false)->index();
        });

        Schema::create('admin_listing_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goalkeeper_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->string('reason', 500);
            $table->dateTime('created_at');
            $table->index(['goalkeeper_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_listing_audits');
        Schema::table('goalkeeper_profiles', function (Blueprint $table) {
            $table->dropIndex(['admin_hidden']);
            $table->dropColumn('admin_hidden');
        });
    }
};
