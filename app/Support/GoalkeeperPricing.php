<?php

namespace App\Support;

use App\Models\City;
use App\Models\Country;

class GoalkeeperPricing
{
    public const CURRENCIES = ['EUR', 'ALL', 'MKD'];

    private const FEATURED_CITIES = [
        'XK' => ['Prishtinë', 'Prizren', 'Pejë', 'Ferizaj', 'Gjilan', 'Gjakovë', 'Mitrovicë', 'Vushtrri', 'Suharekë', 'Rahovec', 'Podujevë', 'Lipjan'],
        'AL' => ['Tiranë', 'Durrës', 'Shkodër', 'Elbasan', 'Vlorë', 'Fier', 'Korçë', 'Berat', 'Lushnjë', 'Lezhë', 'Kamëz', 'Kavajë'],
        'MK' => ['Shkup', 'Tetovo', 'Bitola', 'Kumanovo', 'Prilep', 'Ohrid', 'Gostivar', 'Struga', 'Veles', 'Štip', 'Kičevo', 'Strumica'],
    ];

    public static function allowed(?string $country): array
    {
        return match ($country) {
            'XK' => ['EUR'], 'AL' => ['ALL', 'EUR'], 'MK' => ['MKD', 'EUR'], default => [],
        };
    }

    public static function defaultCurrency(?string $country): string
    {
        return self::allowed($country)[0] ?? 'EUR';
    }

    // All three supported currencies use a 100-minor-unit storage scale. No FX conversion.
    public static function minorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function amount(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function cities(): array
    {
        return City::with('country')
            ->whereHas('country', fn ($query) => $query->whereIn('code', array_keys(self::FEATURED_CITIES)))
            ->where(function ($query) {
                foreach (self::FEATURED_CITIES as $code => $names) {
                    $query->orWhere(function ($countryQuery) use ($code, $names) {
                        $countryQuery->whereHas('country', fn ($query) => $query->where('code', $code))
                            ->whereIn('name', $names);
                    });
                }
            })
            ->orderBy('name')->get()->map(fn ($city) => [
            'id' => $city->id, 'name' => $city->name, 'country_id' => $city->country_id,
            'currencies' => self::allowed($city->country->code), 'defaultCurrency' => self::defaultCurrency($city->country->code),
        ])->all();
    }

    public static function countries(): array
    {
        return Country::orderBy('name')->get()->map(fn ($country) => [
            'id' => $country->id, 'name' => $country->name,
            'currencies' => self::allowed($country->code), 'defaultCurrency' => self::defaultCurrency($country->code),
        ])->all();
    }

    public static function filterDefault(string $currency): int
    {
        return $currency === 'EUR' ? 15 : 10000;
    }
}
