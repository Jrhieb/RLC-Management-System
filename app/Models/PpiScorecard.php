<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PpiScorecardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $version
 * @property string $country
 * @property string $source_reference
 * @property CarbonImmutable $effective_from
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, PpiQuestion> $questions
 */
class PpiScorecard extends Model
{
    /** @use HasFactory<PpiScorecardFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'version',
        'country',
        'source_reference',
        'effective_from',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<PpiQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(PpiQuestion::class)->orderBy('position');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
