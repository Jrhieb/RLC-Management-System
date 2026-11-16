<?php

namespace Tests\Feature;

use App\Models\BeneficiaryApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->post('/application', $data)->assertStatus(405);
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
