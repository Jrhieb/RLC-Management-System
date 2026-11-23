<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PpiHouseholdMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ppi_household_application_id
 * @property string $name
 * @property string|null $relationship
 * @property string|null $monthly_income
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PpiHouseholdApplication|null $application
 */
class PpiHouseholdMember extends Model
{
    /** @use HasFactory<PpiHouseholdMemberFactory> */
    use HasFactory;

    protected $fillable = [
        'ppi_household_application_id',
        'name',
        'relationship',
        'monthly_income',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'monthly_income' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<PpiHouseholdApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(PpiHouseholdApplication::class, 'ppi_household_application_id');
    }
}
