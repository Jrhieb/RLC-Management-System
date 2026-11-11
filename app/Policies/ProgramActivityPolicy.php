<?php

namespace App\Policies;

use App\Models\ProgramActivity;
use App\Models\User;

class ProgramActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isProjectDirector() || $user->isCaseworker();
    }

    public function view(User $user, ProgramActivity $activity): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ProgramActivity $activity): bool
    {
        return $this->viewAny($user);
    }

    public function recordAttendance(User $user, ProgramActivity $activity): bool
    {
        return $this->viewAny($user);
    }
}
