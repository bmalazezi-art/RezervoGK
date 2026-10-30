<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable()->index();
        });

        // Preserve registration history: existing accounts do not receive a fresh trial.
        DB::table('users')->whereNotNull('created_at')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $start = CarbonImmutable::parse($user->created_at, 'UTC');
                DB::table('users')->where('id', $user->id)->update([
                    'trial_started_at' => $start,
                    'trial_ends_at' => $start->addSeconds(30 * 24 * 60 * 60),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['trial_ends_at']);
            $table->dropColumn(['trial_started_at', 'trial_ends_at']);
        });
    }
};
