<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AccountSettingsController extends Controller
{
    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['bail', 'required', 'string', 'confirmed', 'different:current_password', PasswordRule::min(12)->mixedCase()->numbers(),
                function ($attribute, $value, $fail) {
                    if (strlen($value) > 72) {
                        $fail(__('Fjalëkalimi duhet të ketë deri në 72 bajte.'));
                    }
                }],
        ]);

        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();
        $request->session()->regenerate();

        return to_route('dashboard')->with('status', __('Fjalëkalimi u ndryshua.'));
    }
}
