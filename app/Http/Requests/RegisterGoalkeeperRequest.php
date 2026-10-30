<?php

namespace App\Http\Requests;

use App\Support\ProfilePhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterGoalkeeperRequest extends FormRequest
{
    use ProfileFields;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [...$this->profileRules(true),
            'is_admin' => ['prohibited'], 'subscription_ends_at' => ['prohibited'],
            'trial_started_at' => ['prohibited'], 'trial_ends_at' => ['prohibited'],
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')],
            'accept_terms' => ['accepted'],
            'password' => ['bail', 'required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers(),
                function ($attribute, $value, $fail) {
                    if (strlen($value) > 72) {
                        $fail(__('Fjalëkalimi duhet të ketë deri në 72 bajte.'));
                    }
                }],
            'photo' => ProfilePhoto::rules(),
        ];
    }
}
