<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramActivity extends Model
{
    protected $fillable = [
        'title',
        'activity_date',
        'start_time',
        'development_area',
        'location',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        // Reject numeric coercions such as "1e0" before the database matches ID 1.
        if (($field ?? $this->getRouteKeyName()) === 'id' && ! ctype_digit((string) $value)) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }

    /** @return HasMany<ActivityAttendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(ActivityAttendance::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
