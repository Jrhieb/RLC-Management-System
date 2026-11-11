<?php

namespace App\Policies;

use App\Models\Beneficiary;
use App\Models\User;

class BeneficiaryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessStaff();
    }

    public function viewMyCaseload(User $user): bool
    {
        return $this->viewAny($user) && $user->isEligibleCaseworker();
    }

    public function view(User $user, Beneficiary $beneficiary): bool
    {
        return $this->viewAny($user) && ! $beneficiary->trashed()
            && ($user->isProjectDirector() || $beneficiary->caseworker_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->isProjectDirector();
    }

    public function update(User $user, Beneficiary $beneficiary): bool
    {
        return $this->view($user, $beneficiary);
    }

    public function viewArchived(User $user): bool
    {
        return $user->isProjectDirector();
    }

    public function delete(User $user, Beneficiary $beneficiary): bool
    {
        return $user->isProjectDirector() && ! $beneficiary->trashed();
    }

    public function assignCaseworker(User $user, ?Beneficiary $beneficiary = null): bool
    {
        return $user->isProjectDirector() && ($beneficiary === null || ! $beneficiary->trashed());
    }

    public function restore(User $user, Beneficiary $beneficiary): bool
    {
        return $user->isProjectDirector() && $beneficiary->trashed();
    }

    public function forceDelete(User $user, Beneficiary $beneficiary): bool
    {
        return false;
    }
}
