<?php

namespace App\Policies;

use App\Models\PpiHouseholdApplication;
use App\Models\User;

class PpiHouseholdApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isProjectDirector() && $user->hasVerifiedEmail();
    }

    public function view(User $user, PpiHouseholdApplication $application): bool
    {
        return $this->viewAny($user);
    }

    public function updateStatus(User $user, PpiHouseholdApplication $application): bool
    {
        return $this->viewAny($user);
    }
}
