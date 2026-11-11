<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property 'pending'|'approved'|'rejected' $approval_status
 * @property int|null $approval_reviewed_by
 * @property Carbon|null $approval_reviewed_at
 * @property string|null $rejection_reason
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = [
        'approval_status' => 'pending',
    ];

    public function isProjectDirector(): bool
    {
        return $this->role === 'project_director';
    }

    public function isCaseworker(): bool
    {
        return $this->role === 'caseworker';
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    public function isEligibleCaseworker(): bool
    {
        return $this->isCaseworker() && $this->hasVerifiedEmail() && $this->isApproved();
    }

    public function requiresAccountReview(): bool
    {
        return $this->isCaseworker() && ! $this->isApproved();
    }

    public function canAccessStaff(): bool
    {
        return $this->hasVerifiedEmail() && ($this->isProjectDirector() || $this->isEligibleCaseworker());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEligibleCaseworkers(Builder $query): Builder
    {
        return $query->where('role', 'caseworker')
            ->whereNotNull('email_verified_at')
            ->where('approval_status', 'approved');
    }

    /** @return HasMany<Beneficiary, $this> */
    public function assignedBeneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class, 'caseworker_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approval_reviewed_by' => 'integer',
            'approval_reviewed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
