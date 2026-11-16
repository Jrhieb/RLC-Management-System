<?php

namespace App\Policies;

use App\Models\BeneficiaryApplication;
use App\Models\User;

class BeneficiaryApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isProjectDirector() && $user->hasVerifiedEmail();
    }

    public function view(User $user, BeneficiaryApplication $application): bool
    {
        return $this->viewAny($user);
    }
}
