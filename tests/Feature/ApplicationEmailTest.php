<?php

namespace Tests\Feature;

use App\Mail\ApplicationNoticeMail;
use App\Models\BeneficiaryApplication;
use App\Models\User;
use App\Services\ApplicationEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_notice_identifies_the_reference_to_keep_for_follow_up(): void
    {
        $reference = (string) Str::uuid();
        $notice = new ApplicationNoticeMail('Application decision', 'Your application has been waitlisted.', $reference);

        $html = $notice->render();

        $this->assertStringContainsString('Application reference: '.$reference, $html);
        $this->assertStringContainsString('Keep this reference for any follow-up about your application.', $html);
    }

    private function application(string $preference = 'email'): BeneficiaryApplication
    {
        $application = BeneficiaryApplication::submit(['guardian_name' => 'Synthetic Guardian', 'contact_number' => '09123456789', 'email' => 'synthetic@example.test', 'preferred_contact' => 'phone', 'barangay' => 'Test', 'municipality' => 'Test', 'reason' => 'Synthetic']);
        $application->update(['preferred_contact' => $preference]);

        return $application;
    }

    public function test_email_is_accepted_once_and_delivery_is_not_claimed(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'synthetic-message-id'], 201)]);
        config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'synthetic-key']);
        Mail::swap(Mail::getFacadeRoot()->manager);
        $application = $this->application();
        $service = app(ApplicationEmailService::class);
        $event = DB::transaction(fn () => $service->record($application, 'test-notice', 'received', 'Application received', 'Your application is pending review.'));
        $service->deliver($event->id);
        $service->deliver($event->id);
        $this->assertDatabaseHas('application_emails', ['id' => $event->id, 'status' => 'accepted', 'delivery_confirmed_at' => null]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['htmlContent'], $application->reference));
    }

    public function test_phone_preference_does_not_create_an_email_event(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $application = $this->application('phone');
        $event = DB::transaction(fn () => app(ApplicationEmailService::class)->record($application, 'test-notice', 'received', 'Application received', 'Pending review.'));
        $this->assertNull($event);
        $this->assertDatabaseCount('application_emails', 0);
        Http::assertNothingSent();
    }

    public function test_definitive_provider_rejection_can_be_retried_but_ambiguous_failure_cannot(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::sequence()->push(['message' => 'private content'], 401)->push(['messageId' => 'synthetic-message-id'], 201)]);
        config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'synthetic-key']);
        Mail::swap(Mail::getFacadeRoot()->manager);
        $service = app(ApplicationEmailService::class);
        $event = DB::transaction(fn () => $service->record($this->application(), 'test-notice', 'received', 'Application received', 'Pending review.'));
        $service->deliver($event->id);
        $this->assertDatabaseHas('application_emails', ['id' => $event->id, 'status' => 'failed']);
        $service->retry($event->id);
        $this->assertDatabaseHas('application_emails', ['id' => $event->id, 'status' => 'accepted']);
    }

    public function test_ambiguous_provider_failure_cannot_be_retried(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::response([], 500)]);
        config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'synthetic-key']);
        Mail::swap(Mail::getFacadeRoot()->manager);
        $service = app(ApplicationEmailService::class);
        $event = DB::transaction(fn () => $service->record($this->application(), 'notice', 'received', 'Application received', 'Pending review.'));
        $service->deliver($event->id);
        $this->assertDatabaseHas('application_emails', ['id' => $event->id, 'status' => 'uncertain']);
        $this->assertFalse($service->retry($event->id));
        Http::assertSentCount(1);
    }

    public function test_information_email_uses_explicit_applicant_text_not_private_findings(): void
    {
        $application = $this->application();
        $worker = User::factory()->create();
        $application->update(['assigned_caseworker_id' => $worker->id, 'status' => 'for_assessment']);
        $this->actingAs($worker)->post(route('applications.information', $application), ['findings' => 'Private synthetic notes', 'applicant_message' => 'Please contact our office.'])->assertRedirect();
        $this->assertDatabaseHas('application_emails', ['beneficiary_application_id' => $application->id, 'event_type' => 'information_needed', 'applicant_message' => 'Please contact our office.']);
        $this->assertDatabaseMissing('application_emails', ['beneficiary_application_id' => $application->id, 'applicant_message' => 'Private synthetic notes']);
        $this->assertDatabaseHas('beneficiary_applications', ['id' => $application->id, 'status' => 'needs_information']);
    }

    public function test_only_authorized_staff_can_retry_a_definitely_failed_email(): void
    {
        $application = $this->application();
        $worker = User::factory()->create();
        $other = User::factory()->create();
        $application->update(['assigned_caseworker_id' => $worker->id]);
        $event = DB::transaction(fn () => app(ApplicationEmailService::class)->record($application, 'retry-test', 'received', 'Notice', 'Synthetic message.'));
        $event->update(['status' => 'failed']);
        $this->actingAs($other)->post(route('applications.emails.retry', [$application, $event->id]))->assertForbidden();
        $this->actingAs($worker)->post(route('applications.emails.retry', [$application, $event->id]))->assertRedirect();
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $application->id, 'actor_id' => $worker->id, 'event' => 'email_retry_requested']);
        $event->update(['status' => 'uncertain']);
        $this->post(route('applications.emails.retry', [$application, $event->id]))->assertStatus(409);
    }

    public function test_decision_is_saved_when_provider_rejects_the_email(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.brevo.com/*' => Http::response([], 401)]);
        config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'synthetic-key']);
        Mail::swap(Mail::getFacadeRoot()->manager);
        $application = $this->application();
        $application->update(['status' => 'for_decision']);
        $director = User::factory()->create(['role' => 'project_director']);
        $this->actingAs($director)->post(route('applications.decide', $application), ['decision' => 'approved', 'applicant_message' => 'Please contact the office for next steps.'])->assertRedirect();
        $this->assertDatabaseHas('beneficiary_applications', ['id' => $application->id, 'status' => 'approved']);
        $this->assertDatabaseHas('application_emails', ['beneficiary_application_id' => $application->id, 'event_type' => 'approved', 'status' => 'failed']);
        $this->assertStringContainsString('Enrollment is not yet complete', DB::table('application_emails')->where('beneficiary_application_id', $application->id)->value('applicant_message'));
    }

    public function test_confirmed_appointment_creates_only_one_applicant_email_on_retry(): void
    {
        $application = $this->application();
        $director = User::factory()->create(['role' => 'project_director']);
        $input = ['request_key' => (string) Str::uuid(), 'scheduled_at' => '2026-11-20T10:00', 'location_or_method' => 'Synthetic office', 'instructions' => 'Synthetic instructions', 'confirmed' => '1'];
        $this->actingAs($director)->post(route('applications.appointments.store', $application), $input)->assertRedirect();
        $this->post(route('applications.appointments.store', $application), $input)->assertRedirect();
        $this->assertDatabaseCount('application_appointments', 1);
        $this->assertDatabaseCount('application_emails', 1);
        $this->assertDatabaseHas('application_emails', ['beneficiary_application_id' => $application->id, 'event_type' => 'appointment']);
    }

    public function test_receipt_confirmation_requires_authorization_and_explicit_confirmation(): void
    {
        $application = $this->application();
        $director = User::factory()->create(['role' => 'project_director']);
        $other = User::factory()->create();
        $event = DB::transaction(fn () => app(ApplicationEmailService::class)->record($application, 'receipt-test', 'received', 'Notice', 'Synthetic message.'));
        $event->update(['status' => 'accepted']);
        $url = route('applications.emails.receipt', [$application, $event->id]);
        $this->actingAs($other)->post($url, ['recipient_confirmed' => '1'])->assertForbidden();
        $this->actingAs($director)->post($url)->assertSessionHasErrors('recipient_confirmed');
        $this->post($url, ['recipient_confirmed' => '1'])->assertRedirect();
        $this->assertNotNull($event->fresh()->delivery_confirmed_at);
        $this->assertDatabaseHas('beneficiary_application_audits', ['beneficiary_application_id' => $application->id, 'actor_id' => $director->id, 'event' => 'email_receipt_confirmed']);
        $this->post($url, ['recipient_confirmed' => '1'])->assertStatus(409);
    }
}
