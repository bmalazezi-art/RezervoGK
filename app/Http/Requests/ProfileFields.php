<?php

namespace App\Http\Requests;

use App\Models\City;
use App\Models\GoalkeeperProfile;
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
        $primaryCity = is_scalar($cityId) ? City::with('country')->find($cityId) : null;
        $country = $primaryCity?->country?->code;

        return [
            // Extra cities must be in the primary city's country: prices and currencies follow the country.
            'extra_city_ids' => ['sometimes', 'nullable', 'array', 'list', 'max:'.GoalkeeperProfile::MAX_ADDITIONAL_CITIES],
            'extra_city_ids.*' => ['required', 'integer', 'distinct', Rule::exists('cities', 'id')->where('country_id', $primaryCity?->country_id ?? -1)],
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

    public function messages(): array
    {
        return [
            'extra_city_ids.max' => __('Mund të zgjedhësh deri në :max qytete shtesë.', ['max' => GoalkeeperProfile::MAX_ADDITIONAL_CITIES]),
            'extra_city_ids.*.exists' => __('Qytetet shtesë duhet të jenë në të njëjtin shtet me qytetin kryesor.'),
        ];
    }
}
