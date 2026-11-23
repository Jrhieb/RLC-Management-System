<?php

namespace Tests\Feature;

use App\Enums\PpiApplicationStatus;
use App\Models\PpiHouseholdApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PpiTestScorecard;
use Tests\TestCase;

class PpiApplicationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_director_can_handoff_a_passing_application_once(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $caseworker = User::factory()->create();
        $passing = PpiHouseholdApplication::factory()->create(['ppi_score' => 28, 'status' => 'submitted']);
        $screenedOut = PpiHouseholdApplication::factory()->create(['ppi_score' => 29, 'status' => 'did_not_pass_screening']);

        $this->actingAs($caseworker)->post('/ppi-applications/'.$passing->id.'/assessment')->assertForbidden();
        $this->actingAs($director)->post('/ppi-applications/'.$screenedOut->id.'/assessment')->assertConflict();
        $this->get(route('ppi-applications.index'))->assertOk()->assertSee($passing->reference)->assertDontSee($screenedOut->reference);
        $this->get(route('ppi-applications.index', ['screening' => 'not-passed']))->assertOk()
            ->assertSee($screenedOut->reference)->assertDontSee($passing->reference);

        $destination = $this->post('/ppi-applications/'.$passing->id.'/assessment')->assertRedirect()->headers->get('Location');
        $this->post('/ppi-applications/'.$passing->id.'/assessment')->assertRedirect($destination);
        $this->get($destination)->assertOk()->assertSee($passing->reference);
        $this->assertDatabaseCount('beneficiary_applications', 1);
        $this->assertDatabaseHas('beneficiary_applications', ['status' => 'under_review', 'guardian_name' => $passing->guardian_name]);
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    public function test_guests_caseworkers_and_unverified_directors_are_blocked(): void
    {
        $application = PpiHouseholdApplication::factory()->create();
        $caseworker = User::factory()->create();
        $unverifiedDirector = User::factory()->unverified()->create(['role' => 'project_director']);

        $this->getJson(route('ppi-applications.index'))->assertUnauthorized();
        $this->getJson(route('ppi-applications.show', $application))->assertUnauthorized();

        $this->actingAs($caseworker)->getJson(route('ppi-applications.index'))->assertForbidden();
        $this->getJson(route('ppi-applications.show', $application))->assertForbidden();
        $this->patchJson(route('ppi-applications.status.update', $application), [
            'status' => 'under_review',
        ])->assertForbidden();

        $this->actingAs($unverifiedDirector)->getJson(route('ppi-applications.index'))->assertForbidden();

        $this->assertSame(PpiApplicationStatus::Submitted, $application->fresh()->status);
    }

    public function test_director_lists_applications_with_the_lowest_score_first(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $lowest = PpiHouseholdApplication::factory()->create(['ppi_score' => 10]);
        $middle = PpiHouseholdApplication::factory()->create(['ppi_score' => 30]);
        $highest = PpiHouseholdApplication::factory()->create(['ppi_score' => 40]);
        $unscored = PpiHouseholdApplication::factory()->unscored()->create();

        $this->actingAs($director)
            ->getJson(route('ppi-applications.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $lowest->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('data.2.id', $highest->id)
            ->assertJsonPath('data.3.id', $unscored->id)
            ->assertJsonPath('data.0.household_members_count', 0)
            ->assertJsonPath('meta.total', 4)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_director_can_view_the_answers_and_household_members_of_an_application(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $scorecard = PpiTestScorecard::create();
        $application = PpiHouseholdApplication::factory()->create([
            'ppi_scorecard_id' => $scorecard->id,
            'ppi_score' => 55,
        ]);

        $application->members()->create([
            'name' => 'Fictional Household Member',
            'relationship' => 'Mother',
            'monthly_income' => 5000,
            'position' => 1,
        ]);

        foreach ($scorecard->questions as $question) {
            $option = $question->options->firstWhere('code', 'A');

            $application->answers()->create([
                'ppi_question_id' => $question->id,
                'ppi_answer_option_id' => $option->id,
                'points_awarded' => $option->points,
            ]);
        }

        $this->actingAs($director)
            ->getJson(route('ppi-applications.show', $application))
            ->assertOk()
            ->assertJsonPath('data.reference', $application->reference)
            ->assertJsonPath('data.ppi_score', 55)
            ->assertJsonPath('data.scorecard.version', 'test-1')
            ->assertJsonPath('data.guardian.name', $application->guardian_name)
            ->assertJsonPath('data.household_members.0.name', 'Fictional Household Member')
            ->assertJsonPath('data.answers.0.question_code', 'Q1')
            ->assertJsonPath('data.answers.0.points', 1)
            ->assertJsonPath('data.answers.9.question_code', 'Q10')
            ->assertJsonPath('data.answers.9.points', 10)
            ->assertJsonPath('data.status_history', [])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_director_moves_an_application_through_tracked_statuses(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $application = PpiHouseholdApplication::factory()->create([
            'status' => PpiApplicationStatus::Submitted,
        ]);

        $this->actingAs($director)
            ->patchJson(route('ppi-applications.status.update', $application), [
                'status' => 'under_review',
                'note' => 'Synthetic review note',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review');

        $application->refresh();
        $this->assertSame(PpiApplicationStatus::UnderReview, $application->status);
        $this->assertSame($director->id, $application->reviewed_by);
        $this->assertNotNull($application->reviewed_at);
        $this->assertDatabaseHas('ppi_application_status_events', [
            'ppi_household_application_id' => $application->id,
            'from_status' => 'submitted',
            'to_status' => 'under_review',
            'actor_id' => $director->id,
            'note' => 'Synthetic review note',
        ]);

        $this->patchJson(route('ppi-applications.status.update', $application), [
            'status' => 'assessment_complete',
        ])->assertOk()->assertJsonPath('data.status', 'assessment_complete');

        $this->patchJson(route('ppi-applications.status.update', $application), [
            'status' => 'closed',
        ])->assertOk()->assertJsonPath('data.status', 'closed');

        $this->patchJson(route('ppi-applications.status.update', $application), [
            'status' => 'under_review',
        ])->assertStatus(409);

        $this->assertSame(3, $application->statusEvents()->count());

        $this->getJson(route('ppi-applications.show', $application))
            ->assertOk()
            ->assertJsonPath('data.status_history.0.to_status', 'closed')
            ->assertJsonPath('data.status_history.1.to_status', 'assessment_complete')
            ->assertJsonPath('data.status_history.2.to_status', 'under_review')
            ->assertJsonPath('data.status_history.2.actor', $director->name)
            ->assertJsonPath('data.status_history.2.note', 'Synthetic review note');
    }

    public function test_status_cannot_skip_directly_to_assessment_complete(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $application = PpiHouseholdApplication::factory()->create([
            'status' => PpiApplicationStatus::Submitted,
        ]);

        $this->actingAs($director)
            ->patchJson(route('ppi-applications.status.update', $application), [
                'status' => 'assessment_complete',
            ])
            ->assertStatus(409);

        $this->assertSame(PpiApplicationStatus::Submitted, $application->fresh()->status);
        $this->assertDatabaseCount('ppi_application_status_events', 0);
    }

    public function test_non_numeric_application_identifiers_are_not_bound(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);

        $this->actingAs($director)
            ->getJson('/ppi-applications/not-a-number')
            ->assertNotFound();
    }
}
