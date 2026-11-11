<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistanceTransaction extends Model
{
    protected $fillable = [
        'submission_token',
        'beneficiary_id',
        'category',
        'item_name',
        'quantity',
        'released_at',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Beneficiary, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class)->withTrashed();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->canAccessStaff() && $user->isProjectDirector()) {
            return $query;
        }

        return $query->whereHas('beneficiary', fn ($beneficiaries) => $beneficiaries->visibleTo($user));
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
