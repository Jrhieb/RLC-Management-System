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
        $this->withSession(['application_submission_key' => $token])->get('/application')->assertOk()->assertSee('data-real-intake', false);
        $first = $this->postJson('/application', $data)->assertOk()->json('reference');
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
}
