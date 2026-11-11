<?php

namespace App\Services;

use App\Mail\AccountDecisionMail;
use App\Models\AccountNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use LogicException;
use RuntimeException;
use Throwable;

class AccountNotificationService
{
    /** @param array<int, array{added: int, removed: int}> $changes */
    public function assignmentsChanged(array $changes, string $operationId): void
    {
        if ($changes === []) {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Assignment notifications must be stored inside the assignment transaction.');
        }

        // Previous Caseworkers may no longer be eligible for staff access. Keep
        // their removal notice stored; forRecipient hides it until eligible.
        $recipients = User::query()->where('role', 'caseworker')->whereIn('id', array_keys($changes))
            ->orderBy('id')->pluck('id');
        foreach ($recipients as $recipient) {
            $counts = $changes[$recipient];
            if ($counts['added'] === 0 && $counts['removed'] === 0) {
                continue;
            }
            $notification = AccountNotification::firstOrCreate([
                'recipient_id' => $recipient, 'event_key' => 'assignment:'.$operationId,
            ], ['account_id' => $recipient, 'kind' => AccountNotification::ASSIGNMENT,
                'added_count' => $counts['added'], 'removed_count' => $counts['removed']]);
            if (! $notification->exists) {
                throw new RuntimeException('Assignment notification could not be stored.');
            }
        }
    }

    public function registrationCompleted(User $account): void
    {
        User::query()->where('role', 'project_director')->whereNotNull('email_verified_at')
            ->pluck('id')->each(fn ($recipient) => AccountNotification::firstOrCreate([
                'recipient_id' => $recipient, 'event_key' => 'registration:'.$account->id,
            ], ['account_id' => $account->id, 'kind' => AccountNotification::REQUEST]));
    }

    public function decisionSaved(User $account): void
    {
        $notification = AccountNotification::firstOrCreate([
            'recipient_id' => $account->id, 'event_key' => 'decision:'.$account->id,
        ], ['account_id' => $account->id, 'kind' => $account->isApproved() ? AccountNotification::APPROVED : AccountNotification::REJECTED]);

        if (! $notification->wasRecentlyCreated) {
            return;
        }

        // Capture only the registered address and decision, never serialize a User.
        $email = $account->email;
        $status = $account->approval_status;
        DB::afterCommit(function () use ($email, $status): void {
            try {
                Mail::to($email)->queue(new AccountDecisionMail($status));
            } catch (Throwable) {
                // Queue or synchronous transport failure cannot undo a committed review.
                // Transport exceptions can contain credentials; log no exception or payload.
                Log::warning('Account decision email could not be queued.');
            }
        });
    }
}
