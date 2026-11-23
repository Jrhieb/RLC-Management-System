<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ppi_household_application_id
 * @property int $ppi_question_id
 * @property int $ppi_answer_option_id
 * @property int $points_awarded
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PpiHouseholdApplication|null $application
 * @property-read PpiQuestion|null $question
 * @property-read PpiAnswerOption|null $option
 */
class PpiApplicationAnswer extends Model
{
    protected $fillable = [
        'ppi_household_application_id',
        'ppi_question_id',
        'ppi_answer_option_id',
        'points_awarded',
    ];

    protected function casts(): array
    {
        return [
            'ppi_question_id' => 'integer',
            'ppi_answer_option_id' => 'integer',
            'points_awarded' => 'integer',
        ];
    }

    /** @return BelongsTo<PpiHouseholdApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(PpiHouseholdApplication::class, 'ppi_household_application_id');
    }

    /** @return BelongsTo<PpiQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(PpiQuestion::class, 'ppi_question_id');
    }

    /** @return BelongsTo<PpiAnswerOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(PpiAnswerOption::class, 'ppi_answer_option_id');
    }
}
