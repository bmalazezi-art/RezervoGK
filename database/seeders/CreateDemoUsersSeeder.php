<?php

namespace Database\Seeders;

use App\Models\GoalkeeperProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CreateDemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Ardit Krasniqi', 'ardit.demo@rezervogk.test', 'ardit-krasniqi'],
            ['Leon Dervishi', 'leon.demo@rezervogk.test', 'leon-dervishi'],
            ['Enis Hasani', 'enis.demo@rezervogk.test', 'enis-hasani'],
        ] as [$name, $email, $slug]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make('Demo123!'),
                'email_verified_at' => now(),
            ]);
            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            $profile = GoalkeeperProfile::where('slug', $slug)->firstOrFail();
            $profile->user_id = $user->id;
            $profile->is_published = true;
            $profile->is_suspended = false;
            $profile->admin_hidden = false;
            $profile->save();
        }
    }
}
