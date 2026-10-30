<?php

namespace App\Http\Requests;

use App\Support\ProfilePhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateGoalkeeperRequest extends FormRequest
{
    use ProfileFields;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('keeper')) ?? false;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->boolean('remove_photo') && $this->hasFile('photo')) {
                $validator->errors()->add('photo', __('Zgjidh një fotografi të re ose hiqe fotografinë ekzistuese.'));
            }
        }];
    }

    public function rules(): array
    {
        return [...$this->profileRules(),
            'is_admin' => ['prohibited'], 'subscription_ends_at' => ['prohibited'],
            'trial_started_at' => ['prohibited'], 'trial_ends_at' => ['prohibited'],
            'formats' => ['required', 'array', 'min:1', 'max:10'],
            'experience_years' => ['required', 'integer', 'min:0', 'max:100'],
            'languages' => ['required', 'array', 'min:1', 'max:10'],
            'languages.*' => ['required', 'string', 'max:40', 'distinct:ignore_case'],
            'whatsapp' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'photo' => ProfilePhoto::rules(),
            'remove_photo' => ['sometimes', 'boolean'],
            'photo_path' => ['prohibited'], 'photo_url' => ['prohibited'],
        ];
    }
}
