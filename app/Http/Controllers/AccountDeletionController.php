<?php

namespace App\Http\Controllers;

use App\Support\AccountDeleter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountDeletionController extends Controller
{
    public function __invoke(Request $request, AccountDeleter $deleter): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && ! $user->is_admin && ! $user->hasSuperAdminIdentity() && $user->goalkeeperProfile()->exists(), 403);
        $data = $request->validate(['confirmation' => ['required', 'accepted']]);

        $deleter->delete($user);
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', __('Llogaria dhe profili u fshinë.'));
    }
}
