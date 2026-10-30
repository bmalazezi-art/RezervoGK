<?php

namespace Database\Seeders;

use App\Models\GoalkeeperProfile;
use App\Support\AvailabilityCalendar;
use Illuminate\Database\Seeder;

class GoalkeeperAvailabilityDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['ardit-krasniqi' => [0, 2], 'leon-dervishi' => [1], 'enis-hasani' => [2], 'altin-berisha' => [4]] as $slug => $offsets) {
            $profile = GoalkeeperProfile::where('slug', $slug)->first();
            if (! $profile) {
                continue;
            }
            foreach ($offsets as $offset) {
                $profile->availabilities()->firstOrCreate(['available_on' => AvailabilityCalendar::today()->addDays($offset)->toDateString()]);
            }
        }
    }
}
