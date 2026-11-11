<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['recipient_id', 'account_id', 'kind', 'event_key', 'added_count', 'removed_count'])]
class AccountNotification extends Model
{
    use HasUuids;

    public const REQUEST = 'account_request';

    public const APPROVED = 'account_approved';

    public const REJECTED = 'account_rejected';

    public const ASSIGNMENT = 'beneficiary_assignment';

    protected function casts(): array
    {
        return ['recipient_id' => 'integer', 'account_id' => 'integer', 'read_at' => 'immutable_datetime',
            'added_count' => 'integer', 'removed_count' => 'integer'];
    }

    public function scopeForRecipient(Builder $query, User $user): Builder
    {
        $query->where('recipient_id', $user->id);

        $kinds = [self::APPROVED, self::REJECTED];
        if ($user->isEligibleCaseworker()) {
            $kinds[] = self::ASSIGNMENT;
        }

        return $user->isProjectDirector() && $user->hasVerifiedEmail()
            ? $query->where('kind', self::REQUEST)
            : $query->where('account_id', $user->id)->whereIn('kind', $kinds);
    }

    public function title(): string
    {
        return match ($this->kind) {
            self::REQUEST => 'New account request',
            self::APPROVED => 'Account approved',
            self::REJECTED => 'Account request rejected',
            self::ASSIGNMENT => 'Caseload updated',
        };
    }

    public function message(): string
    {
        return match ($this->kind) {
            self::REQUEST => 'A Caseworker completed registration and is awaiting review.',
            self::APPROVED => 'Your account has been approved. Check your account status to continue.',
            self::REJECTED => 'Your account request was rejected. Check your account status for the reason.',
            self::ASSIGNMENT => 'Your caseload changed: '.$this->added_count.' added, '.$this->removed_count.' removed.',
        };
    }

    public function destination(): string
    {
        return route(match ($this->kind) {
            self::REQUEST => 'account-requests.index',
            self::ASSIGNMENT => 'my-caseload.index',
            default => 'account.status',
        }, absolute: false);
    }
}
