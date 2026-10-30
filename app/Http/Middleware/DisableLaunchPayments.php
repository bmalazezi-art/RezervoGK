<?php

namespace App\Http\Middleware;

use App\Support\LaunchModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DisableLaunchPayments
{
    public function handle(Request $request, Closure $next): Response
    {
        // Before authentication/binding: no receipt lookup, audit write or payment action is reachable.
        abort_if(LaunchModel::isFree() && LaunchModel::paymentPath($request->decodedPath()), 404);

        return $next($request);
    }
}
