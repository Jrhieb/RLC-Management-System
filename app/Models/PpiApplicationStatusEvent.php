<?php

namespace App\Models;

use App\Enums\PpiApplicationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ppi_household_application_id
 * @property PpiApplicationStatus|null $from_status
 * @property PpiApplicationStatus $to_status
 * @property int|null $actor_id
 * @property string|null $note
 * @property CarbonImmutable $created_at
 * @property-read PpiHouseholdApplication|null $application
 * @property-read User|null $actor
 */
class PpiApplicationStatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ppi_household_application_id',
        'from_status',
        'to_status',
        'actor_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => PpiApplicationStatus::class,
            'to_status' => PpiApplicationStatus::class,
            'actor_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PpiHouseholdApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(PpiHouseholdApplication::class, 'ppi_household_application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
