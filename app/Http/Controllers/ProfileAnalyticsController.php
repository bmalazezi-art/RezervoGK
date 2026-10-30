<?php

namespace App\Http\Controllers;

use App\Models\GoalkeeperProfile;
use App\Support\ProfileAnalytics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProfileAnalyticsController extends Controller
{
    public function store(Request $request, GoalkeeperProfile $keeper, ProfileAnalytics $analytics): Response
    {
        $data = $request->validate(['event' => ['required', 'string', 'in:whatsapp,phone']]);
        $analytics->record($request, $keeper, $data['event']);

        return response()->noContent();
    }
}
