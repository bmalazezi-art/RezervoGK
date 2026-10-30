<?php

namespace App\Http\Requests;

use App\Support\ProfilePhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('keeper')) ?? false;
    }

    public function rules(): array
    {
        return ['photo' => ProfilePhoto::rules(), 'remove_photo' => ['sometimes', 'boolean'],
            'photo_path' => ['prohibited'], 'photo_url' => ['prohibited'], 'user_id' => ['prohibited']];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->any()) {
                return;
            }
            if (! $this->hasFile('photo') && ! $this->boolean('remove_photo')) {
                $validator->errors()->add('photo', __('Zgjidh një fotografi përpara ruajtjes.'));
            }
            if ($this->hasFile('photo') && $this->boolean('remove_photo')) {
                $validator->errors()->add('photo', __('Zgjidh një fotografi të re ose hiqe fotografinë ekzistuese.'));
            }
        }];
    }
}
