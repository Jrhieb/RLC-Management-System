<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PpiQuestionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $ppi_scorecard_id
 * @property string $code
 * @property string $prompt
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PpiScorecard|null $scorecard
 * @property-read Collection<int, PpiAnswerOption> $options
 */
class PpiQuestion extends Model
{
    /** @use HasFactory<PpiQuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'ppi_scorecard_id',
        'code',
        'prompt',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<PpiScorecard, $this> */
    public function scorecard(): BelongsTo
    {
        return $this->belongsTo(PpiScorecard::class, 'ppi_scorecard_id');
    }

    /** @return HasMany<PpiAnswerOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(PpiAnswerOption::class, 'ppi_question_id')->orderBy('position');
    }
}
