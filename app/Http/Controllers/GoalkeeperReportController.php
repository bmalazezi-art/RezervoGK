<?php

namespace App\Http\Controllers;

use App\Models\GoalkeeperProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoalkeeperReportController extends Controller
{
    public function store(Request $request, GoalkeeperProfile $keeper)
    {
        abort_unless(GoalkeeperProfile::publiclyVisible()->whereKey($keeper->id)->exists() && $keeper->user_id !== null, 404);
        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(['inaccurate', 'inappropriate', 'spam', 'other'])],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);
        $keeper->reports()->create($data);

        return back()->with('status', __('Raporti u dërgua. Faleminderit.'));
    }
}
