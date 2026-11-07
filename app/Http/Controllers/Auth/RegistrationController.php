<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterGoalkeeperRequest;
use App\Models\MatchFormat;
use App\Models\User;
use App\Support\GoalkeeperPricing;
use App\Support\GoalkeeperTrial;
use App\Support\LaunchModel;
use App\Support\ProfilePhoto;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Register', [
            'countries' => GoalkeeperPricing::countries(),
            'cities' => GoalkeeperPricing::cities(),
            'formats' => MatchFormat::inPreferredOrder()->pluck('code'),
        ]);
    }

    public function store(RegisterGoalkeeperRequest $request, ProfilePhoto $photos): RedirectResponse
    {
        $data = $request->validated();
        $path = $request->hasFile('photo') ? $photos->store($request->file('photo')) : null;
        try {
            $user = DB::transaction(function () use ($data, $path) {
                $user = User::create([
                    'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                    'accepted_terms_version' => User::CURRENT_POLICY_VERSION,
                    'accepted_terms_at' => now(),
                ]);
                if (! LaunchModel::isFree()) {
                    GoalkeeperTrial::start($user);
                }
                $profile = $user->goalkeeperProfile()->create([
                    'name' => $data['name'], 'city_id' => $data['city_id'], 'bio' => $data['bio'],
                    'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(12)),
                    'price_cents' => GoalkeeperPricing::minorUnits((string) $data['price']), 'currency' => $data['currency'],
                    'photo_url' => $path ? Storage::disk('public')->url($path) : '/keeper-placeholder.svg',
                    'languages' => ['Shqip'], 'availability_label' => 'Sipas marrëveshjes',
                ]);
                $profile->photo_path = $path;
                $profile->save();
                $profile->contact()->create(['phone' => $data['phone'], 'whatsapp' => ltrim($data['phone'], '+')]);
                $profile->matchFormats()->sync(MatchFormat::whereIn('code', $data['formats'])->pluck('id'));

                return $user;
            });
        } catch (Throwable $exception) {
            if ($path) {
                $photos->delete($path);
            }
            throw $exception;
        }
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard')->with('status', __('Llogaria dhe profili yt u krijuan.'));
    }
}
