<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfilePhotoRequest;
use App\Models\GoalkeeperProfile;
use App\Support\ProfilePhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProfilePhotoController extends Controller
{
    public function update(UpdateProfilePhotoRequest $request, GoalkeeperProfile $keeper, ProfilePhoto $photos): RedirectResponse
    {
        $path = null;
        try {
            $path = $request->hasFile('photo') ? $photos->store($request->file('photo')) : null;
            $oldPath = DB::transaction(function () use ($keeper, $path) {
                $profile = GoalkeeperProfile::whereKey($keeper->id)->lockForUpdate()->firstOrFail();
                Gate::authorize('update', $profile);
                $oldPath = $profile->photo_path;
                $profile->photo_path = $path;
                $profile->photo_url = $path ? Storage::disk('public')->url($path) : '/keeper-placeholder.svg';
                $profile->save();

                return $oldPath;
            });
        } catch (Throwable $exception) {
            $this->cleanup($photos, $path);
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            throw ValidationException::withMessages(['photo' => __('Fotografia nuk u ruajt. Provo përsëri.')]);
        }
        // A cleanup failure must not turn a committed photo save into a false failure.
        $this->cleanup($photos, $oldPath);

        return to_route('dashboard')->with('photo_status', $path ? __('Fotografia u ruajt.') : __('Fotografia u hoq.'));
    }

    private function cleanup(ProfilePhoto $photos, ?string $path): void
    {
        try {
            $photos->delete($path);
        } catch (Throwable) {
            // Leave any orphan for storage maintenance; never remove the current image.
        }
    }
}
