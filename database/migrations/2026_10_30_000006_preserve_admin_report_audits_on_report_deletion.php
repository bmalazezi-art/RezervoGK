<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_report_audits', function (Blueprint $table) {
            $table->dropForeign(['goalkeeper_profile_report_id']);
            $table->unsignedBigInteger('goalkeeper_profile_report_id')->nullable()->change();
            $table->foreign('goalkeeper_profile_report_id', 'admin_report_audits_report_fk')
                ->references('id')->on('goalkeeper_profile_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('admin_report_audits')->whereNull('goalkeeper_profile_report_id')->exists()) {
            throw new RuntimeException('Cannot restore required report references while orphaned report audit history exists.');
        }

        Schema::table('admin_report_audits', function (Blueprint $table) {
            $table->dropForeign('admin_report_audits_report_fk');
            $table->unsignedBigInteger('goalkeeper_profile_report_id')->nullable(false)->change();
            $table->foreign('goalkeeper_profile_report_id')->references('id')->on('goalkeeper_profile_reports')->cascadeOnDelete();
        });
    }
};
