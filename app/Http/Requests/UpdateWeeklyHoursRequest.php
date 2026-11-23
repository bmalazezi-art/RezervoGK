<?php

namespace App\Http\Requests;

use App\Support\AvailabilityCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateWeeklyHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('keeper')) ?? false;
    }

    public function rules(): array
    {
        return [
            'days' => ['present', 'array', 'list', 'max:7'],
            'days.*' => ['required', 'array:weekday,start,end,city_id'],
            'days.*.city_id' => ['nullable', 'integer'],
            'days.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'days.*.start' => ['required', 'date_format:H:i'],
            'days.*.end' => ['required', 'date_format:H:i'],
            'offDates' => ['sometimes', 'array', 'list', 'max:30'],
            'offDates.*' => ['required', 'distinct', ...AvailabilityCalendar::dateRules()],
            'extraDates' => ['sometimes', 'array', 'list', 'max:30'],
            'extraDates.*' => ['required', 'distinct', ...AvailabilityCalendar::dateRules()],
            // Extra date => city id; a date without an entry is in the primary city.
            'extraDateCities' => ['sometimes', 'array'],
            'extraDateCities.*' => ['nullable', 'integer'],
            'user_id' => ['prohibited'], 'goalkeeper_profile_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->any()) {
                return;
            }
            $cityIds = $this->route('keeper')->serviceCityIds();
            foreach ($this->input('days') as $index => $day) {
                if ($day['start'] === $day['end']) {
                    $validator->errors()->add("days.$index.end", __('Ora e përfundimit duhet të ndryshojë nga ora e fillimit.'));
                }
                if (isset($day['city_id']) && ! in_array((int) $day['city_id'], $cityIds, true)) {
                    $validator->errors()->add("days.$index.city_id", __('Zgjidh një nga qytetet e profilit tënd.'));
                }
            }
            foreach ((array) $this->input('extraDateCities', []) as $date => $cityId) {
                if (! in_array($date, (array) $this->input('extraDates', []), true)) {
                    $validator->errors()->add('extraDateCities', __('Qyteti mund të caktohet vetëm për një datë shtesë.'));
                } elseif ($cityId !== null && ! in_array((int) $cityId, $cityIds, true)) {
                    $validator->errors()->add("extraDateCities.$date", __('Zgjidh një nga qytetet e profilit tënd.'));
                }
            }
        }];
    }

    public function messages(): array
    {
        return ['days.*.start.date_format' => __('Ora e fillimit duhet të jetë në formatin OO:MM, nga 00:00 deri në 23:59.'),
            'days.*.end.date_format' => __('Ora e përfundimit duhet të jetë në formatin OO:MM. Për mesnatë përdor 00:00 (ditën tjetër).')];
    }
}
