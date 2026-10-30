<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletion_file_cleanups', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 16);
            $table->string('path', 255);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['disk', 'path']);
        });
    }

    public function down(): void
    {
        if (DB::table('account_deletion_file_cleanups')->exists()) {
            throw new RuntimeException('Cannot remove the account deletion cleanup queue while files still require cleanup.');
        }

        Schema::dropIfExists('account_deletion_file_cleanups');
    }
};
