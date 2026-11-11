<?php

namespace App\Services;

use App\Models\User;

class CaseworkerCaseload
{
    public function summary(User $user): array
    {
        // Both queries use the authenticated user's relationship and its default
        // soft-delete scope. No client-supplied owner or archived filter is used.
        $counts = $user->assignedBeneficiaries()->visibleTo($user)->selectRaw(
            'COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS active, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS inactive',
            ['active', 'inactive'],
        )->toBase()->first();

        return [
            'caseloadCounts' => ['total' => (int) $counts->total, 'active' => (int) $counts->active, 'inactive' => (int) $counts->inactive],
            'caseloadPreview' => $user->assignedBeneficiaries()->visibleTo($user)->alphabetical()->limit(5)
                ->get(['id', 'full_name', 'beneficiary_id', 'status', 'photo_path', 'caseworker_id']),
        ];
    }
}
