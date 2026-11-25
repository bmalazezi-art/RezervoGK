<?php

namespace App\Http\Requests;

use App\Models\City;
use App\Models\Country;
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
        $dialCode = $this->countryDialCode();
        foreach (['phone', 'whatsapp'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = self::internationalPhone(preg_replace('/[\s().\/-]/', '', $this->input($field)), $dialCode);
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

    private const DIAL_CODES = ['XK' => '383', 'AL' => '355', 'MK' => '389'];

    /**
     * People type their number the way they say it ("044 123 456", "00383 44…", "38344…"), and the plain "+383…"
     * rule rejected those, which sent registrations back to the first step. Rewrite the common local forms into
     * the international form; anything else is left as typed for the validation rule to judge.
     */
    private static function internationalPhone(string $phone, ?string $dialCode): string
    {
        if (str_starts_with($phone, '00')) {
            return '+'.substr($phone, 2);
        }
        if (str_starts_with($phone, '+') || ! ctype_digit($phone)) {
            return $phone;
        }
        foreach (self::DIAL_CODES as $code) {
            if (str_starts_with($phone, $code) && strlen($phone) >= 11) {
                return '+'.$phone;
            }
        }
        if ($dialCode && str_starts_with($phone, '0') && strlen($phone) >= 8) {
            return '+'.$dialCode.substr($phone, 1);
        }

        return $phone;
    }

    /** The dialling code of the country the profile is in: the chosen country, the chosen city, or the existing profile. */
    private function countryDialCode(): ?string
    {
        $countryId = $this->input('country_id');
        $cityId = $this->input('city_id');
        $code = is_scalar($countryId) && $countryId !== '' ? Country::whereKey($countryId)->value('code')
            : (is_scalar($cityId) && $cityId !== '' ? City::with('country')->find($cityId)?->country?->code
                : $this->route('keeper')?->city?->country?->code);

        return self::DIAL_CODES[$code] ?? null;
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
