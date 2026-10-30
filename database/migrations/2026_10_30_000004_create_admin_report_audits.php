<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_report_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goalkeeper_profile_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 24);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            $table->dateTime('created_at');
            $table->index(['goalkeeper_profile_report_id', 'created_at'], 'admin_report_audits_report_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_report_audits');
    }
};
