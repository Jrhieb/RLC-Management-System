<?php

namespace Tests\Feature;

use App\Models\BeneficiaryApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class BeneficiaryApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_enrolls_an_approved_application_through_the_beneficiary_form_once(): void
    {
        $application = BeneficiaryApplication::submit([
            'guardian_name' => 'Synthetic Guardian', 'contact_number' => '09123456789',
            'barangay' => 'Sample', 'municipality' => 'Sample', 'reason' => 'Private synthetic reason',
            'preferred_contact' => 'email', 'email' => 'synthetic@example.test',
        ]);
        $director = User::factory()->create(['role' => 'project_director']);
        $worker = User::factory()->create();
        $application->update(['assigned_caseworker_id' => $worker->id]);
        $form = route('applications.enrollment.create', $application);
        $save = route('applications.enrollment.store', $application);
        $payload = ['beneficiary_id' => 'SYN-005', 'full_name' => 'Synthetic Beneficiary', 'registered_at' => '2026-11-19'];

        $this->actingAs($director)->get($form)->assertStatus(409);
        $this->postJson($save, $payload)->assertStatus(409);
        $application->update(['status' => 'approved']);
        $this->actingAs($worker)->get($form)->assertForbidden();
        $this->postJson($save, $payload)->assertForbidden();

        $this->actingAs($director)->get(route('applications.show', $application))->assertSee($form);
        $this->get($form)->assertOk()->assertSee('Synthetic Guardian')->assertSee('09123456789')->assertDontSee('Private synthetic reason');
        $this->from($form)->post($save, [])->assertRedirect($form)->assertSessionHasErrors(['beneficiary_id', 'full_name', 'registered_at']);
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertSame('approved', $application->fresh()->status);
        $this->assertDatabaseMissing('application_emails', ['event_type' => 'enrollment_completed']);

        $this->post($save, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $enrolled = $application->fresh();
        $this->assertSame('enrolled', $enrolled->status);
        $this->assertDatabaseHas('beneficiaries', ['id' => $enrolled->beneficiary_id, 'beneficiary_id' => 'SYN-005']);
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $application->id, 'actor_id' => $director->id, 'event' => 'enrolled']);
        $this->assertDatabaseHas('application_emails', ['beneficiary_application_id' => $application->id, 'event_type' => 'enrollment_completed']);
        $this->get(route('applications.show', $application))->assertSee(route('beneficiaries.show', $enrolled->beneficiary_id))->assertDontSee($form);
        $this->postJson($save, ['beneficiary_id' => 'SYN-006'] + $payload)->assertStatus(409);
        $this->assertDatabaseCount('beneficiaries', 1);
    }

    public function test_director_can_view_applications_and_caseworker_cannot(): void
    {
        $application = BeneficiaryApplication::submit(['guardian_name' => 'Test Guardian', 'contact_number' => '09123456789', 'barangay' => 'Test', 'municipality' => 'Test City', 'reason' => 'Synthetic request']);
        $director = User::factory()->create(['role' => 'project_director']);
        $worker = User::factory()->create();
        $this->actingAs($director)->get(route('applications.index'))->assertOk()->assertSee('Test Guardian');
        $this->get(route('applications.show', $application))->assertOk()->assertSee('Synthetic request')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($worker)->get(route('applications.index'))->assertForbidden();
        $this->get(route('applications.show', $application))->assertForbidden();
    }

    public function test_submission_storage_generates_unique_references_and_audits(): void
    {
        $data = ['guardian_name' => 'Test Guardian', 'contact_number' => '09123456789', 'barangay' => 'Test', 'municipality' => 'Test City', 'reason' => 'Synthetic request'];
        $first = BeneficiaryApplication::submit($data);
        $second = BeneficiaryApplication::submit($data);
        $this->assertNotSame($first->reference, $second->reference);
        $this->assertSame('pending_review', $first->status);
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $first->id, 'status' => 'pending_review']);
        $this->post('/application', $data)->assertStatus(403);
    }

    public function test_enabled_intake_saves_once_and_notifies_director(): void
    {
        config(['services.application_intake.enabled' => true, 'services.application_intake.privacy_contact' => 'Synthetic contact', 'services.application_intake.privacy_acknowledgment' => 'Synthetic acknowledgment', 'services.application_intake.retention_notice' => 'Synthetic retention']);
        $token = (string) Str::uuid();
        $data = ['submission_key' => $token, 'guardian_name' => 'Synthetic Guardian', 'guardian_relationship' => 'Parent', 'contact_number' => '09123456789', 'preferred_contact' => 'email', 'email' => 'synthetic@example.test', 'barangay' => 'Test', 'municipality' => 'Test City', 'reason' => 'Synthetic reason', 'acknowledgment' => '1'];
        $first = $this->withSession(['application_submission_key' => $token])->postJson('/application', $data)->assertOk()->json('reference');
        $this->postJson('/application', $data)->assertOk()->assertJsonPath('reference', $first);
        $this->assertDatabaseCount('beneficiary_applications', 1);
        $application = BeneficiaryApplication::firstOrFail();
        $this->assertSame('pending_review', $application->status);
        $this->assertNotEmpty($application->reference);
        $this->assertSame($first, $application->reference);
        $this->assertSame('Parent', $application->guardian_relationship);
        $this->assertSame('email', $application->preferred_contact);
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $application->id, 'event' => 'submitted']);
        $director = User::factory()->create(['role' => 'project_director']);
        $this->actingAs($director)->get(route('applications.index'))->assertOk()->assertSee('Synthetic Guardian');
        $this->getJson(route('dashboard.notifications'))->assertJsonFragment(['title' => 'Application awaiting review']);
        Mail::assertNothingOutgoing();
    }

    public function test_enabled_intake_validates_fields(): void
    {
        config(['services.application_intake.enabled' => true, 'services.application_intake.privacy_contact' => 'Synthetic contact', 'services.application_intake.privacy_acknowledgment' => 'Synthetic acknowledgment', 'services.application_intake.retention_notice' => 'Synthetic retention']);
        $token = (string) Str::uuid();
        $this->withSession(['application_submission_key' => $token])->post('/application', ['submission_key' => $token])->assertSessionHasErrors(['guardian_name', 'contact_number', 'barangay', 'municipality', 'reason']);
        $this->assertDatabaseCount('beneficiary_applications', 0);
    }

    public function test_intake_flag_alone_does_not_open_without_privacy_settings(): void
    {
        config(['services.application_intake.enabled' => true, 'services.application_intake.privacy_contact' => null]);
        $this->post('/application', [])->assertForbidden();
        $this->get('/application')->assertDontSee('data-real-intake', false);
        $this->assertDatabaseCount('beneficiary_applications', 0);
    }

    public function test_dashboard_notifications_only_show_applications_to_directors(): void
    {
        BeneficiaryApplication::submit(['guardian_name' => 'Private Guardian', 'contact_number' => '09123456789', 'barangay' => 'Test', 'municipality' => 'Test City', 'reason' => 'Private reason']);
        $director = User::factory()->create(['role' => 'project_director']);
        $worker = User::factory()->create();

        $this->actingAs($director)->get(route('dashboard'))->assertOk()->assertSee('Applications')->assertSee('Pending review');
        $this->getJson(route('dashboard.notifications'))->assertOk()->assertJsonFragment(['title' => 'Application awaiting review'])->assertDontSee('Private Guardian');
        $this->actingAs($worker)->getJson(route('dashboard.notifications'))->assertOk()->assertDontSee('Application awaiting review')->assertDontSee('Private Guardian');
    }

    public function test_director_can_assign_and_only_assigned_caseworker_can_assess(): void
    {
        $application = BeneficiaryApplication::submit(['guardian_name' => 'Synthetic Guardian', 'contact_number' => '09123456789', 'barangay' => 'Test', 'municipality' => 'Test City', 'reason' => 'Synthetic request']);
        $director = User::factory()->create(['role' => 'project_director']);
        $worker = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($director)->post(route('applications.assign', $application), ['caseworker_id' => $worker->id])->assertRedirect();
        $this->assertDatabaseHas('beneficiary_applications', ['id' => $application->id, 'assigned_caseworker_id' => $worker->id, 'status' => 'for_assessment']);
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $application->id, 'actor_id' => $director->id, 'event' => 'assigned']);
        $this->actingAs($other)->get(route('applications.show', $application))->assertForbidden();
        $this->post(route('applications.assess', $application), ['findings' => 'Private findings', 'recommendation' => 'Synthetic recommendation'])->assertForbidden();
        $this->actingAs($worker)->get(route('applications.show', $application))->assertOk();
        $this->post(route('applications.assess', $application), ['findings' => 'Private findings', 'recommendation' => 'Synthetic recommendation'])->assertRedirect();
        $this->assertDatabaseHas('beneficiary_applications', ['id' => $application->id, 'status' => 'for_decision']);
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $application->id, 'actor_id' => $worker->id, 'event' => 'assessment_submitted']);
        $this->post(route('applications.decide', $application), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($director)->post(route('applications.decide', $application), ['decision' => 'approved'])->assertRedirect();
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $application->id, 'actor_id' => $director->id, 'event' => 'decision', 'status' => 'approved']);
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->post(route('applications.decide', $application), ['decision' => 'declined'])->assertStatus(409);
    }

    public function test_review_and_information_needed_are_audited_and_ineligible_assignment_is_rejected(): void
    {
        $application = BeneficiaryApplication::submit(['guardian_name' => 'Synthetic Guardian', 'contact_number' => '09123456789', 'barangay' => 'Test', 'municipality' => 'Test City', 'reason' => 'Synthetic request']);
        $director = User::factory()->create(['role' => 'project_director']);
        $worker = User::factory()->create();
        $pending = User::factory()->pendingApproval()->create();
        $this->actingAs($director)->post(route('applications.review', $application))->assertRedirect();
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $application->id, 'actor_id' => $director->id, 'status' => 'under_review']);
        $this->post(route('applications.assign', $application), ['caseworker_id' => $pending->id])->assertNotFound();
        $this->post(route('applications.assign', $application), ['caseworker_id' => $worker->id])->assertRedirect();
        $this->actingAs($worker)->post(route('applications.information', $application), ['findings' => 'Synthetic information request'])->assertRedirect();
        $this->assertDatabaseHas('beneficiary_applications', ['id' => $application->id, 'information_needed' => 'Synthetic information request']);
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $application->id, 'actor_id' => $worker->id, 'status' => 'needs_information']);
        $this->get(route('applications.assigned'))->assertOk()->assertSee('Synthetic Guardian');
    }
}
