<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ppi_household_application_id
 * @property int $recipient_id
 * @property string $kind
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PpiHouseholdApplication|null $application
 * @property-read User|null $recipient
 */
class PpiDirectorNotification extends Model
{
    public const NEW_APPLICATION = 'new_application';

    protected $fillable = [
        'ppi_household_application_id',
        'recipient_id',
        'kind',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'ppi_household_application_id' => 'integer',
            'recipient_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PpiHouseholdApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(PpiHouseholdApplication::class, 'ppi_household_application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForRecipient(Builder $query, User $user): Builder
    {
        if (! $user->isProjectDirector() || ! $user->hasVerifiedEmail()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('recipient_id', $user->id);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function title(): string
    {
        return 'New PPI application';
    }

    public function message(): string
    {
        return 'A household submitted a PPI application for review.';
    }

    public function destination(): string
    {
        return route('ppi-applications.show', $this->ppi_household_application_id, absolute: false);
    }
}
