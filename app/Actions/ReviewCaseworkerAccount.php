<?php

namespace App\Actions;

use App\Models\User;
use App\Services\AccountNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReviewCaseworkerAccount
{
    public function __construct(private AccountNotificationService $notifications) {}

    /** @param array<string, mixed> $input */
    public function execute(User $reviewer, User $account, array $input): bool
    {
        Gate::forUser($reviewer)->authorize('reviewAccountRequests', User::class);

        if (is_string($input['rejection_reason'] ?? null)) {
            $input['rejection_reason'] = trim($input['rejection_reason']);
        }

        $validated = Validator::make($input, [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'confirmed' => ['accepted'],
            'rejection_reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000'],
        ])->validate();

        // The conditional write is the decision lock, including on SQLite. A stale or
        // concurrent request cannot change a completed review or its reviewer metadata.
        return DB::transaction(function () use ($account, $reviewer, $validated): bool {
            $reviewed = User::query()->whereKey($account->getKey())
                ->where('role', 'caseworker')->where('approval_status', 'pending')
                ->update([
                    'approval_status' => $validated['decision'] === 'approve' ? 'approved' : 'rejected',
                    'approval_reviewed_by' => $reviewer->getKey(),
                    'approval_reviewed_at' => now(),
                    'rejection_reason' => $validated['decision'] === 'reject' ? $validated['rejection_reason'] : null,
                ]) === 1;

            if ($reviewed) {
                $this->notifications->decisionSaved($account->fresh());
            }

            return $reviewed;
        }, attempts: 3);
    }
}
