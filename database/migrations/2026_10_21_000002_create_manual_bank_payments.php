<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_admin')->default(false));
        Schema::create('manual_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->unsignedInteger('duration_seconds');
            $table->string('status', 20)->default('draft')->index();
            $table->json('bank_snapshot');
            $table->date('paid_on')->nullable();
            $table->text('sender_note')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_mime', 50)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('bank_transaction_reference', 120)->nullable()->unique();
            $table->date('received_on')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('manual_payment_id')->unique()->constrained()->restrictOnDelete();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->index();
            $table->timestamps();
        });
        Schema::create('payment_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manual_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 30);
            $table->json('metadata');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_audits');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('manual_payments');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
