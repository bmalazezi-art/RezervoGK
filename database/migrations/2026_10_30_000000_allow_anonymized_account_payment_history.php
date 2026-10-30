<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_payments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('payment_audits', function (Blueprint $table) {
            $table->dropForeign(['actor_id']);
            $table->unsignedBigInteger('actor_id')->nullable()->change();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('manual_payments')->whereNull('user_id')->exists()
            || \Illuminate\Support\Facades\DB::table('subscriptions')->whereNull('user_id')->exists()
            || \Illuminate\Support\Facades\DB::table('payment_audits')->whereNull('actor_id')->exists()) {
            throw new RuntimeException('Cannot restore required account references after anonymized deletions.');
        }
        Schema::table('payment_audits', function (Blueprint $table) {
            $table->dropForeign(['actor_id']);
            $table->unsignedBigInteger('actor_id')->nullable(false)->change();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('manual_payments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
};
