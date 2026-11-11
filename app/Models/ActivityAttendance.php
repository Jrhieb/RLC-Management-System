<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityAttendance extends Model
{
    protected $fillable = [
        'program_activity_id',
        'beneficiary_id',
        'checked_in_at',
        'source',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeMatchingBeneficiary(Builder $query, string $search): Builder
    {
        return $query->when($search !== '', function ($query) use ($search) {
            $query->whereHas('beneficiary', function ($beneficiaryQuery) use ($search) {
                $beneficiaryQuery->where(function ($match) use ($search) {
                    $match->where('full_name', 'like', "%{$search}%")
                        ->orWhere('beneficiary_id', 'like', "%{$search}%");
                });
            });
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        // Directors retain historical rows, including archived beneficiaries.
        if ($user?->canAccessStaff() && $user->isProjectDirector()) {
            return $query;
        }

        return $query->whereHas('beneficiary', fn ($beneficiaries) => $beneficiaries->visibleTo($user));
    }

    /** @return BelongsTo<ProgramActivity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(ProgramActivity::class, 'program_activity_id');
    }

    /** @return BelongsTo<Beneficiary, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
