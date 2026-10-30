<?php

namespace App\Policies;

use App\Models\GoalkeeperProfile;
use App\Models\User;

class GoalkeeperProfilePolicy
{
    public function update(User $user, GoalkeeperProfile $goalkeeperProfile): bool
    {
        return $goalkeeperProfile->user_id !== null && $goalkeeperProfile->user_id === $user->id;
    }
}
