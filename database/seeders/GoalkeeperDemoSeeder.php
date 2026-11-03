<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\GoalkeeperProfile;
use App\Models\MatchFormat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GoalkeeperDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach (['XK' => 'Kosovë', 'AL' => 'Shqipëri', 'MK' => 'Maqedoni e Veriut'] as $code => $name) {
                Country::updateOrCreate(['code' => $code], ['name' => $name]);
            }
            $formats = collect(['5v5', '6v6', '7v7', '11v11'])->mapWithKeys(
                fn ($code) => [$code => MatchFormat::firstOrCreate(['code' => $code])->id]
            );
            $rows = [
                ['ardit-krasniqi', 'Ardit Krasniqi', 'XK', 'Prishtinë', 1000, 4.9, 28, ['5v5', '6v6'], '1721909795454-1cf6ee50f8cf', 'Sot', '38344111222', true],
                ['leon-dervishi', 'Leon Dervishi', 'AL', 'Tiranë', 700, 4.8, 19, ['5v5', '7v7'], '1711812176453-5b70a6f0a984', 'Nesër', '35568111222', true],
                ['enis-hasani', 'Enis Hasani', 'MK', 'Shkup', 1000, 5, 34, ['6v6', '7v7'], '1599204606471-32a75bc663e9', 'Sot', '38970111222', true],
                ['altin-berisha', 'Altin Berisha', 'XK', 'Prizren', 500, 4.7, 14, ['5v5', '6v6'], '1792005121928-a8a295721f68', 'Të premten', '38349111222', false],
            ];
            foreach ($rows as [$slug, $name, $countryCode, $cityName, $cents, $rating, $reviews, $codes, $photo, $available, $phone, $featured]) {
                $country = Country::where('code', $countryCode)->firstOrFail();
                $city = City::firstOrCreate(['country_id' => $country->id, 'name' => $cityName]);
                $profile = GoalkeeperProfile::updateOrCreate(['slug' => $slug], [
                    'city_id' => $city->id, 'name' => $name,
                    'bio' => "Portier aktiv me përvojë në futboll të vogël. Reagime të shpejta, komunikim i mirë me mbrojtjen dhe gjithmonë në kohë. Luaj në zonën e {$cityName}.",
                    'photo_url' => "https://images.unsplash.com/photo-{$photo}?auto=format&fit=crop&w=900&q=88",
                    'price_cents' => $cents, 'currency' => 'EUR',
                    'experience_years' => 3, 'languages' => ['Shqip', 'English'],
                    'rating' => $rating, 'review_count' => $reviews, 'matches_played' => $reviews + 14,
                    'availability_label' => $available, 'is_verified' => true, 'is_featured' => $featured,
                ]);
                $profile->contact()->updateOrCreate([], ['phone' => '+'.$phone, 'whatsapp' => $phone]);
                $profile->matchFormats()->sync(collect($codes)->map(fn ($code) => $formats[$code])->all());
            }
        });
    }
}
