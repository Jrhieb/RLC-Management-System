<?php

namespace Tests\Feature;

use App\Mail\ApplicationNoticeMail;
use App\Models\PpiAnswerOption;
use App\Models\PpiHouseholdApplication;
use App\Models\PpiQuestion;
use App\Models\PpiScorecard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\PpiTestScorecard;
use Tests\TestCase;

class PpiApplicationSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_page_shows_a_four_step_preview_without_opening_intake(): void
    {
        config(['ppi.intake.enabled' => false]);

        $this->get(route('program-application'))
            ->assertOk()
            ->assertSeeInOrder(['Household Details', 'PPI Questions', 'Household Members', 'Review &amp; Submit'], false)
            ->assertSee('Skip this step')
            ->assertSee('Sample preview')
            ->assertDontSee('name="submission_key"', false);

        $this->assertDatabaseCount('ppi_household_applications', 0);
    }

    public function test_live_form_uses_the_session_key_and_requires_both_notification_channels(): void
    {
        $this->enableIntake();
        PpiTestScorecard::create();
        config(['services.brevo.sms_enabled' => false]);
        $this->get(route('program-application'))->assertOk()->assertSee('Sample preview');
        $this->postJson(route('ppi-applications.store'), [])->assertStatus(503);

        config(['services.brevo.sms_enabled' => true]);
        $firstForm = $this->get(route('program-application'))->assertOk()
            ->assertDontSee('Sample preview')
            ->assertSee('action="/ppi-applications"', false)
            ->assertSee('Fictional PPI question 1')
            ->assertDontSee('"points"', false)
            ->assertSessionHas('ppi_opened_forms')->viewData('submissionKey');
        $otherForm = $this->get(route('program-application'))->assertOk()->viewData('submissionKey');

        $this->assertNotSame($firstForm, $otherForm);
        $this->assertSame([$firstForm, $otherForm], session('ppi_opened_forms'));
    }

    public function test_phone_only_results_use_sms_once_and_delivery_failure_preserves_the_application(): void
    {
        $this->enableIntake();
        Http::preventStrayRequests();
        Http::fake(['https://api.brevo.com/v3/transactionalSMS/send' => Http::response([], 503)]);
        $scorecard = PpiTestScorecard::create();
        $payload = PpiTestScorecard::payload(PpiTestScorecard::answers($scorecard, 'B'), ['email' => null]);

        $first = $this->withSession(['ppi_submission_key' => $payload['submission_key']])
            ->postJson(route('ppi-applications.store'), $payload)->assertCreated()->json('reference');
        $this->postJson(route('ppi-applications.store'), $payload)->assertOk()->assertJsonPath('reference', $first);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['recipient'] === '+639171234567'
            && str_contains($request['content'], $first)
            && ! str_contains($request['content'], 'score'));
        $this->assertDatabaseHas('ppi_household_applications', ['reference' => $first, 'guardian_delivery_status' => 'failed']);
        Mail::assertNothingOutgoing();
    }

    public function test_screening_boundary_is_inclusive_and_keeps_points_private(): void
    {
        $this->enableIntake();
        $scorecard = PpiTestScorecard::create();
        $director = User::factory()->create(['role' => 'project_director']);
        $positive = PpiTestScorecard::answers($scorecard);
        $zero = PpiTestScorecard::answers($scorecard, 'B');

        foreach ([28 => 6, 29 => 7] as $score => $lastAnswer) {
            $answers = array_replace($zero, array_slice($positive, 0, 6), [$lastAnswer => $positive[$lastAnswer]]);
            $payload = PpiTestScorecard::payload($answers, [
                'complete_address' => $score.' Test Street',
                'household_members' => [['name' => 'Sample member', 'monthly_income' => null]],
            ]);

            $response = $this->withSession(['ppi_submission_key' => $payload['submission_key']])
                ->postJson(route('ppi-applications.store'), $payload)
                ->assertCreated()
                ->assertJsonMissingPath('ppi_score')
                ->assertJsonPath('status', $score === 28 ? 'submitted' : 'did_not_pass_screening');

            if ($score === 28) {
                $this->assertNotEmpty($response->json('reference'));
            } else {
                $response->assertJsonMissingPath('reference')->assertJsonPath(
                    'message',
                    'Thank you for taking the time to apply to RLCP. After our initial review, we’re unable to move your application to the next stage of the program.',
                );
            }
        }

        $this->assertDatabaseHas('ppi_household_applications', ['ppi_score' => 28, 'status' => 'submitted']);
        $this->assertDatabaseHas('ppi_household_applications', ['ppi_score' => 29, 'status' => 'did_not_pass_screening']);
        $this->assertDatabaseHas('ppi_household_members', ['name' => 'Sample member', 'monthly_income' => null]);
        Mail::assertSentCount(2);
        Mail::assertSent(ApplicationNoticeMail::class, fn (ApplicationNoticeMail $mail): bool => $mail->reference === null
            && str_contains($mail->applicantMessage, 'unable to move your application'));
        $this->actingAs($director)->getJson(route('dashboard.notifications'))->assertOk()
            ->assertJsonCount(2, 'items')->assertJsonPath('items.0.title', 'New PPI application');
    }

    public function test_intake_stays_closed_until_privacy_configuration_is_complete(): void
    {
        config([
            'ppi.intake.enabled' => false,
            'ppi.intake.privacy_contact' => null,
            'ppi.intake.privacy_acknowledgment' => null,
            'ppi.intake.retention_notice' => null,
        ]);

        $this->postJson(route('ppi-applications.store'), [])->assertForbidden();

        config([
            'ppi.intake.enabled' => true,
            'ppi.intake.privacy_contact' => null,
            'ppi.intake.privacy_acknowledgment' => 'Synthetic acknowledgment',
            'ppi.intake.retention_notice' => 'Synthetic retention notice',
        ]);

        $this->postJson(route('ppi-applications.store'), [])->assertForbidden();
        $this->assertDatabaseCount('ppi_household_applications', 0);
    }

    public function test_intake_requires_an_active_scorecard(): void
    {
        $this->enableIntake();

        $this->postJson(route('ppi-applications.store'), [])
            ->assertStatus(503);
        $this->assertDatabaseCount('ppi_household_applications', 0);
    }

    public function test_incomplete_active_scorecard_blocks_intake(): void
    {
        $this->enableIntake();

        $scorecard = PpiScorecard::factory()->create();

        foreach (range(1, 9) as $position) {
            PpiQuestion::factory()->for($scorecard, 'scorecard')->create([
                'code' => 'Q'.$position,
                'position' => $position,
            ]);
        }

        $this->postJson(route('ppi-applications.store'), [])
            ->assertStatus(503);
        $this->assertDatabaseCount('ppi_household_applications', 0);
    }

    public function test_submission_is_scored_stored_and_notifies_verified_directors(): void
    {
        $this->enableIntake();
        $scorecard = PpiTestScorecard::create();
        $director = User::factory()->create(['role' => 'project_director']);
        $unverifiedDirector = User::factory()->unverified()->create(['role' => 'project_director']);
        $caseworker = User::factory()->create();
        $token = (string) Str::uuid();
        $payload = PpiTestScorecard::payload(PpiTestScorecard::answers($scorecard, 'B'));

        $response = $this->withSession(['ppi_submission_key' => $token])
            ->postJson(route('ppi-applications.store'), [
                'submission_key' => $token,
            ] + $payload);

        $response->assertCreated()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('message', 'Thank you for applying to RLCP. Your application has been submitted. Please wait for further notice from us by email or phone.')
            ->assertJsonMissingPath('ppi_score')
            ->assertHeader('Cache-Control', 'no-store, private');

        $reference = $response->json('reference');
        $this->assertIsString($reference);
        $this->assertMatchesRegularExpression('/^PPI-\d{4}-[A-Z2-9]{8}$/', $reference);

        $application = PpiHouseholdApplication::query()->sole();
        $this->assertSame($reference, $application->reference);
        $this->assertSame($scorecard->id, $application->ppi_scorecard_id);
        $this->assertSame(0, $application->ppi_score);
        $this->assertSame('submitted', $application->status->value);
        $this->assertNotNull($application->privacy_acknowledged_at);
        $this->assertNotNull($application->submitted_at);
        $this->assertNotNull($application->scored_at);
        $this->assertSame(10, $application->answers()->count());
        $this->assertSame(0, (int) $application->answers()->sum('points_awarded'));
        $this->assertSame(1, $application->members()->count());
        $this->assertDatabaseHas('ppi_application_status_events', [
            'ppi_household_application_id' => $application->id,
            'from_status' => null,
            'to_status' => 'submitted',
            'actor_id' => null,
        ]);
        $this->assertDatabaseHas('ppi_director_notifications', [
            'ppi_household_application_id' => $application->id,
            'recipient_id' => $director->id,
            'kind' => 'new_application',
        ]);
        $this->assertDatabaseMissing('ppi_director_notifications', ['recipient_id' => $unverifiedDirector->id]);
        $this->assertDatabaseMissing('ppi_director_notifications', ['recipient_id' => $caseworker->id]);
        $this->assertDatabaseCount('beneficiaries', 0);
        Mail::assertSent(ApplicationNoticeMail::class, fn (ApplicationNoticeMail $mail): bool => $mail->hasTo($payload['email']) && $mail->reference === $reference);
    }

    public function test_repeating_the_same_submission_key_returns_the_original_application(): void
    {
        $this->enableIntake();
        $scorecard = PpiTestScorecard::create();
        $token = (string) Str::uuid();
        $payload = PpiTestScorecard::payload(PpiTestScorecard::answers($scorecard, 'B'));

        $first = $this->withSession(['ppi_submission_key' => $token])
            ->postJson(route('ppi-applications.store'), ['submission_key' => $token] + $payload)
            ->assertCreated()
            ->json('reference');

        $second = $this->postJson(route('ppi-applications.store'), ['submission_key' => $token] + $payload)
            ->assertOk()
            ->json('reference');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('ppi_household_applications', 1);
        $this->assertDatabaseCount('ppi_application_answers', 10);
        Mail::assertSentCount(1);
    }

    public function test_duplicate_households_are_rejected_even_with_a_new_submission_key(): void
    {
        $this->enableIntake();
        $scorecard = PpiTestScorecard::create();
        $firstToken = (string) Str::uuid();
        $firstPayload = PpiTestScorecard::payload(PpiTestScorecard::answers($scorecard), [
            'contact_number' => '0917-123-4567',
        ]);

        $this->withSession(['ppi_submission_key' => $firstToken])
            ->postJson(route('ppi-applications.store'), ['submission_key' => $firstToken] + $firstPayload)
            ->assertCreated();

        $secondToken = (string) Str::uuid();
        $secondPayload = PpiTestScorecard::payload(PpiTestScorecard::answers($scorecard), [
            'submission_key' => $secondToken,
            'contact_number' => '+639171234567',
            'child_first_name' => 'Another fictional child',
        ]);

        $this->withSession(['ppi_submission_key' => $secondToken])
            ->postJson(route('ppi-applications.store'), $secondPayload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'An application for this household has already been received.');

        $this->assertDatabaseCount('ppi_household_applications', 1);
    }

    public function test_submission_requires_a_session_bound_token(): void
    {
        $this->enableIntake();
        $scorecard = PpiTestScorecard::create();
        $token = (string) Str::uuid();

        $this->postJson(
            route('ppi-applications.store'),
            ['submission_key' => $token] + PpiTestScorecard::payload(PpiTestScorecard::answers($scorecard)),
        )->assertForbidden();

        $this->assertDatabaseCount('ppi_household_applications', 0);
    }

    public function test_submission_validates_every_required_field_and_answer(): void
    {
        $this->enableIntake();
        $scorecard = PpiTestScorecard::create();
        $token = (string) Str::uuid();

        $this->withSession(['ppi_submission_key' => $token])
            ->postJson(route('ppi-applications.store'), ['submission_key' => $token])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'child_first_name',
                'child_last_name',
                'child_sex',
                'child_date_of_birth',
                'guardian_name',
                'guardian_relationship',
                'contact_number',
                'barangay',
                'city_municipality',
                'complete_address',
                'privacy_acknowledgment',
                'answers',
            ]);

        $answers = PpiTestScorecard::answers($scorecard);
        array_pop($answers);

        $this->postJson(
            route('ppi-applications.store'),
            ['submission_key' => $token] + PpiTestScorecard::payload($answers),
        )->assertStatus(422)->assertJsonValidationErrors(['answers']);

        $this->assertDatabaseCount('ppi_household_applications', 0);
    }

    public function test_a_negative_score_is_stored_as_a_signed_value(): void
    {
        $this->enableIntake();

        $scorecard = PpiScorecard::factory()->create(['version' => 'test-1']);

        foreach (range(1, 10) as $position) {
            $question = PpiQuestion::factory()->for($scorecard, 'scorecard')->create([
                'code' => 'Q'.$position,
                'position' => $position,
            ]);

            PpiAnswerOption::factory()->for($question, 'question')->create([
                'code' => 'A',
                'points' => -2,
                'position' => 1,
            ]);

            PpiAnswerOption::factory()->for($question, 'question')->create([
                'code' => 'B',
                'points' => 1,
                'position' => 2,
            ]);
        }

        $scorecard = $scorecard->fresh(['questions.options']);
        $token = (string) Str::uuid();

        $this->withSession(['ppi_submission_key' => $token])
            ->postJson(
                route('ppi-applications.store'),
                ['submission_key' => $token] + PpiTestScorecard::payload(PpiTestScorecard::answers($scorecard, 'A')),
            )
            ->assertCreated();

        $this->assertDatabaseHas('ppi_household_applications', ['ppi_score' => -20]);
    }

    private function enableIntake(): void
    {
        config([
            'ppi.intake.enabled' => true,
            'ppi.intake.privacy_contact' => 'Synthetic privacy contact',
            'ppi.intake.privacy_acknowledgment' => 'Synthetic privacy acknowledgment',
            'ppi.intake.retention_notice' => 'Synthetic retention notice',
            'ppi.scorecard.version' => 'test-1',
            'mail.mailers.brevo.key' => 'synthetic-mail',
            'services.brevo.sms_enabled' => true,
            'services.brevo.sms_key' => 'synthetic-sms',
            'services.brevo.sms_sender' => 'RLCP',
            'services.brevo.sms_country_codes' => ['63'],
            'services.brevo.sms_daily_limit' => 5,
        ]);
    }
}
