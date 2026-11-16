<?php

namespace Tests\Feature;

use App\Models\BeneficiaryApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_director_and_assigned_caseworker_can_record_private_contact(): void
    {
        $application = BeneficiaryApplication::submit(['guardian_name' => 'Synthetic Guardian', 'contact_number' => '09123456789', 'barangay' => 'Sample', 'municipality' => 'Sample', 'reason' => 'Synthetic']);
        $director = User::factory()->create(['role' => 'project_director']);
        $worker = User::factory()->create();
        $other = User::factory()->create();
        $application->update(['assigned_caseworker_id' => $worker->id]);
        $input = ['contact_at' => '2026-11-16T14:30', 'method' => 'phone', 'outcome' => 'reached', 'summary' => 'Synthetic summary', 'follow_up_on' => '2026-11-20', 'private_notes' => 'Synthetic private note'];
        $this->actingAs($other)->post(route('applications.contacts.store', $application), $input)->assertForbidden();
        $this->assertDatabaseCount('application_contacts', 0);
        $this->actingAs($worker)->post(route('applications.contacts.store', $application), $input)->assertRedirect();
        $this->assertDatabaseHas('application_contacts', ['beneficiary_application_id' => $application->id, 'actor_id' => $worker->id, 'method' => 'phone', 'outcome' => 'reached', 'summary' => 'Synthetic summary', 'contact_at' => '2026-11-16 06:30:00']);
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $application->id, 'actor_id' => $worker->id, 'event' => 'contact_recorded']);
        $this->actingAs($director)->get(route('applications.show', $application))->assertOk()->assertSee('Synthetic private note')->assertSee('Nov 16, 2026 2:30 PM');
        $this->actingAs($other)->get(route('applications.show', $application))->assertForbidden();
    }

    public function test_contact_requires_valid_method_outcome_and_date(): void
    {
        $application = BeneficiaryApplication::submit(['guardian_name' => 'Synthetic Guardian', 'contact_number' => '09123456789', 'barangay' => 'Sample', 'municipality' => 'Sample', 'reason' => 'Synthetic']);
        $director = User::factory()->create(['role' => 'project_director']);
        $this->actingAs($director)->post(route('applications.contacts.store', $application), ['contact_at' => 'bad', 'method' => 'sms', 'outcome' => 'unknown'])->assertSessionHasErrors(['contact_at', 'method', 'outcome', 'summary']);
        $this->assertDatabaseCount('application_contacts', 0);
    }

    public function test_only_authorized_staff_can_confirm_an_appointment_and_retry_does_not_duplicate_it(): void
    {
        $application = BeneficiaryApplication::submit(['guardian_name' => 'Synthetic Guardian', 'contact_number' => '09123456789', 'barangay' => 'Sample', 'municipality' => 'Sample', 'reason' => 'Synthetic']);
        $worker = User::factory()->create();
        $other = User::factory()->create();
        $application->update(['assigned_caseworker_id' => $worker->id]);
        $input = ['request_key' => (string) Str::uuid(), 'scheduled_at' => '2026-11-20T10:00', 'location_or_method' => 'Synthetic office', 'instructions' => 'Synthetic instructions', 'confirmed' => '1'];
        $this->actingAs($other)->post(route('applications.appointments.store', $application), $input)->assertForbidden();
        $this->actingAs($worker)->post(route('applications.appointments.store', $application), array_diff_key($input, ['confirmed' => true]))->assertSessionHasErrors('confirmed');
        $this->post(route('applications.appointments.store', $application), $input)->assertRedirect();
        $this->post(route('applications.appointments.store', $application), $input)->assertRedirect();
        $this->assertDatabaseCount('application_appointments', 1);
        $this->assertDatabaseHas('application_appointments', ['beneficiary_application_id' => $application->id, 'scheduled_at' => '2026-11-20 02:00:00']);
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $application->id, 'actor_id' => $worker->id, 'event' => 'appointment_confirmed']);
        $this->assertDatabaseCount('application_emails', 0);
    }
}
