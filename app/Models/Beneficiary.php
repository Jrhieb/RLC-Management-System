<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Beneficiary extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'beneficiary_id',
        'full_name',
        'registered_at',
        'status',
        'photo_path',
        'birthdate',
        'address',
        'guardian_name',
        'guardian_contact',
        'school',
        'grade_level',
    ];

    protected function casts(): array
    {
        return [
            'registered_at' => 'date',
            'birthdate' => 'date',
            'caseworker_id' => 'integer',
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        // Keep malformed numeric strings from matching a different primary key.
        if (($field ?? $this->getRouteKeyName()) === 'id' && ! ctype_digit((string) $value)) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }

    /** @return BelongsTo<User, $this> */
    public function caseworker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caseworker_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user?->canAccessStaff()) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isProjectDirector()) {
            return $query;
        }

        // Explicitly exclude archives even on historical withTrashed relations.
        return $query->where($query->qualifyColumn('caseworker_id'), $user->id)
            ->whereNull($query->qualifyColumn('deleted_at'));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeMatchingSearch(Builder $query, string $search): Builder
    {
        return $query->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
            $query->where('beneficiary_id', 'like', '%'.$search.'%')
                ->orWhere('full_name', 'like', '%'.$search.'%');
        }));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAlphabetical(Builder $query): Builder
    {
        return $query->orderBy('full_name')->orderBy('id');
    }
}
