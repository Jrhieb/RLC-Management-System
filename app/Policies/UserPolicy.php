<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAccountNotifications(User $user): bool
    {
        return $user->isCaseworker() || ($user->isProjectDirector() && $user->hasVerifiedEmail());
    }

    public function reviewAccountRequests(User $user): bool
    {
        return $user->isProjectDirector() && $user->hasVerifiedEmail();
    }

    public function createCaseworker(User $user): bool
    {
        return false;
    }
}
