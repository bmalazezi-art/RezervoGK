<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manual_payment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('decision', 16);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->uuid('reservation')->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_email_deliveries');
    }
};
