<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PpiAnswerOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ppi_question_id
 * @property string $code
 * @property string $label
 * @property int $points
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PpiQuestion|null $question
 */
class PpiAnswerOption extends Model
{
    /** @use HasFactory<PpiAnswerOptionFactory> */
    use HasFactory;

    protected $fillable = [
        'ppi_question_id',
        'code',
        'label',
        'points',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<PpiQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(PpiQuestion::class, 'ppi_question_id');
    }
}
