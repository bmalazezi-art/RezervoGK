<?php

namespace App\Http\Requests;

use App\Models\City;
use App\Support\GoalkeeperPricing;
use Illuminate\Validation\Rule;

trait ProfileFields
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        if (is_string($this->input('email'))) {
            $normalized['email'] = mb_strtolower(trim((string) $this->input('email')));
        }
        foreach (['phone', 'whatsapp'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = preg_replace('/[\s().-]/', '', $this->input($field));
            }
        }
        if (is_array($this->input('languages'))) {
            $normalized['languages'] = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $this->input('languages'));
        }
        if (! $this->exists('currency')) {
            $cityId = $this->input('city_id');
            $country = is_scalar($cityId) ? City::with('country')->find($cityId)?->country?->code : null;
            $normalized['currency'] = $this->route('keeper')?->currency ?? GoalkeeperPricing::defaultCurrency($country);
        }
        $this->merge($normalized);
    }

    protected function profileRules(bool $registration = false): array
    {
        $city = Rule::exists('cities', 'id');
        if ($registration) {
            $countryId = $this->input('country_id');
            $city->where('country_id', is_scalar($countryId) ? $countryId : -1);
        }

        $cityId = $this->input('city_id');
        $country = is_scalar($cityId) ? City::with('country')->find($cityId)?->country?->code : null;

        return [
            'currency' => ['required', 'string', Rule::in(GoalkeeperPricing::allowed($country))],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'city_id' => ['required', 'integer', $city],
            'bio' => ['required', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:10000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'formats' => [$registration ? 'required' : 'sometimes', 'array', 'min:1', 'max:10'],
            'formats.*' => ['required', 'string', 'distinct', Rule::exists('match_formats', 'code')],
            'is_published' => ['prohibited'], 'is_suspended' => ['prohibited'],
            'user_id' => ['prohibited'], 'is_verified' => ['prohibited'],
            'is_featured' => ['prohibited'], 'rating' => ['prohibited'],
        ];
    }
}
