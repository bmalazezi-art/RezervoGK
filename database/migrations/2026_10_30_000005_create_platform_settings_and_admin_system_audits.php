<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        DB::table('platform_settings')->insert([
            ['key' => 'registration_enabled', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'announcement', 'value' => '', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('admin_system_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason', 500);
            $table->dateTime('created_at');
            $table->index(['action', 'created_at']);
            $table->index(['target_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_system_audits');
        Schema::dropIfExists('platform_settings');
    }
};
