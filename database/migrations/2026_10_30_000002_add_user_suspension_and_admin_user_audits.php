<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_suspended')->default(false)->index();
        });

        Schema::create('admin_user_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->string('reason', 500);
            $table->dateTime('created_at');
            $table->index(['target_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_user_audits');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_suspended']);
            $table->dropColumn('is_suspended');
        });
    }
};
