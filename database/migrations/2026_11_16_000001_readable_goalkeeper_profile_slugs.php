<?php

use App\Models\GoalkeeperProfile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Replace "name-x7k2..." slugs with readable ones for /p/{slug} links. */
    public function up(): void
    {
        GoalkeeperProfile::query()->orderBy('id')->each(function (GoalkeeperProfile $profile) {
            $slug = GoalkeeperProfile::uniqueSlug($profile->name, $profile->id);
            if ($slug !== $profile->slug) {
                $profile->forceFill(['slug' => $slug])->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        // Old random suffixes are not needed; /keepers/{id} links keep working.
    }
};
