<?php

namespace App\Models;

use App\Enums\PpiApplicationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PpiHouseholdApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $reference
 * @property string $submission_key
 * @property string $household_key
 * @property int $ppi_scorecard_id
 * @property string $child_first_name
 * @property string|null $child_middle_name
 * @property string $child_last_name
 * @property string|null $child_suffix
 * @property string $child_sex
 * @property CarbonImmutable $child_date_of_birth
 * @property string $guardian_name
 * @property string $guardian_relationship
 * @property string $contact_number
 * @property string|null $email
 * @property string $barangay
 * @property string $city_municipality
 * @property string $complete_address
 * @property int|null $ppi_score
 * @property PpiApplicationStatus $status
 * @property string|null $guardian_delivery_status
 * @property int|null $beneficiary_application_id
 * @property CarbonImmutable|null $privacy_acknowledged_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $scored_at
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read PpiScorecard|null $scorecard
 * @property-read Collection<int, PpiHouseholdMember> $members
 * @property-read Collection<int, PpiApplicationAnswer> $answers
 * @property-read Collection<int, PpiApplicationStatusEvent> $statusEvents
 * @property-read Collection<int, PpiDirectorNotification> $directorNotifications
 * @property-read User|null $reviewer
 */
class PpiHouseholdApplication extends Model
{
    /** @use HasFactory<PpiHouseholdApplicationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'child_first_name',
        'child_middle_name',
        'child_last_name',
        'child_suffix',
        'child_sex',
        'child_date_of_birth',
        'guardian_name',
        'guardian_relationship',
        'contact_number',
        'email',
        'barangay',
        'city_municipality',
        'complete_address',
        'privacy_acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'child_date_of_birth' => 'date',
            'ppi_score' => 'integer',
            'ppi_scorecard_id' => 'integer',
            'reviewed_by' => 'integer',
            'status' => PpiApplicationStatus::class,
            'privacy_acknowledged_at' => 'datetime',
            'submitted_at' => 'datetime',
            'scored_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PpiScorecard, $this> */
    public function scorecard(): BelongsTo
    {
        return $this->belongsTo(PpiScorecard::class, 'ppi_scorecard_id');
    }

    /** @return BelongsTo<BeneficiaryApplication, $this> */
    public function beneficiaryApplication(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryApplication::class);
    }

    /** @return HasMany<PpiHouseholdMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(PpiHouseholdMember::class, 'ppi_household_application_id')->orderBy('position');
    }

    /** @return HasMany<PpiApplicationAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(PpiApplicationAnswer::class, 'ppi_household_application_id')
            ->orderBy('ppi_question_id');
    }

    /** @return HasMany<PpiApplicationStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(PpiApplicationStatusEvent::class, 'ppi_household_application_id')
            ->latest('created_at')
            ->latest('id');
    }

    /** @return HasMany<PpiDirectorNotification, $this> */
    public function directorNotifications(): HasMany
    {
        return $this->hasMany(PpiDirectorNotification::class, 'ppi_household_application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLowestScoreFirst(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN ppi_score IS NULL THEN 1 ELSE 0 END')
            ->orderBy('ppi_score')
            ->orderBy('submitted_at')
            ->orderBy('id');
    }
}
