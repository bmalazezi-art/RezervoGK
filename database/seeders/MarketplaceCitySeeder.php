<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MarketplaceCitySeeder extends Seeder
{
    /**
     * Most popular marketplace cities. Kosovo and Albania use Albanian spellings;
     * North Macedonia uses Latin-script names.
     *
     * @return array<string, list<string>>
     */
    public static function locations(): array
    {
        return [
            'XK' => [
                'Prishtinë', 'Prizren', 'Pejë', 'Ferizaj', 'Gjilan', 'Gjakovë', 'Mitrovicë', 'Vushtrri',
                'Suharekë', 'Rahovec', 'Podujevë', 'Lipjan',
            ],
            'AL' => [
                'Tiranë', 'Durrës', 'Shkodër', 'Elbasan', 'Vlorë', 'Fier', 'Korçë', 'Berat', 'Lushnjë',
                'Lezhë', 'Kamëz', 'Kavajë',
            ],
            'MK' => [
                'Shkup', 'Tetovo', 'Bitola', 'Kumanovo', 'Prilep', 'Ohrid', 'Gostivar', 'Struga', 'Veles',
                'Štip', 'Kičevo', 'Strumica',
            ],
        ];
    }

    public function run(): void
    {
        $now = now();
        DB::table('countries')->insertOrIgnore([
            ['code' => 'XK', 'name' => 'Kosovë', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'AL', 'name' => 'Shqipëri', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'MK', 'name' => 'Maqedoni e Veriut', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $countries = DB::table('countries')->whereIn('code', array_keys(self::locations()))->pluck('id', 'code');

        if ($countries->count() !== count(self::locations())) {
            throw new RuntimeException('Create the three supported marketplace countries before loading their locations.');
        }

        $rows = [];

        foreach (self::locations() as $countryCode => $names) {
            foreach ($names as $name) {
                $rows[] = [
                    'country_id' => $countries[$countryCode],
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('cities')->insertOrIgnore($rows);
    }
}
