<?php

namespace App\Services;

use App\Mail\ApplicationNoticeMail;
use App\Mail\BrevoDeliveryException;
use App\Models\ApplicationEmail;
use App\Models\BeneficiaryApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Throwable;

class ApplicationEmailService
{
    public function record(BeneficiaryApplication $application, string $eventKey, string $eventType, string $subject, string $message, ?int $actorId = null): ?ApplicationEmail
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Application email events require a transaction.');
        }
        if ($eventType === 'enrollment_completed' && ($application->status !== 'enrolled' || $application->beneficiary_id === null)) {
            throw new LogicException('Enrollment email requires a linked beneficiary.');
        }
        if ($application->preferred_contact !== 'email' || ! filter_var($application->email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $event = ApplicationEmail::firstOrCreate(
            ['beneficiary_application_id' => $application->id, 'event_key' => $eventKey],
            ['event_type' => $eventType, 'subject' => $subject, 'applicant_message' => $message, 'created_by' => $actorId, 'status' => 'pending'],
        );
        if ($event->wasRecentlyCreated) {
            DB::afterCommit(function () use ($event): void {
                try {
                    $this->deliver($event->id);
                } catch (Throwable) {
                    Log::warning('Application email processing failed after the application was saved.');
                }
            });
        }

        return $event;
    }

    public function deliver(int $id): void
    {
        if (ApplicationEmail::query()->whereKey($id)->where('status', 'pending')->update(['status' => 'sending']) !== 1) {
            return;
        }
        $event = ApplicationEmail::query()->findOrFail($id);
        $application = BeneficiaryApplication::query()->find($event->beneficiary_application_id);
        if (! $application || $application->preferred_contact !== 'email' || ! filter_var($application->email, FILTER_VALIDATE_EMAIL) || config('mail.default') !== 'brevo') {
            $event->update(['status' => 'failed']);

            return;
        }
        try {
            $sent = Mail::mailer('brevo')->to($application->email)->send(new ApplicationNoticeMail($event->subject, $event->applicant_message, $application->reference));
            if (! $sent || ! $sent->getMessageId()) {
                $event->update(['status' => 'uncertain']);

                return;
            }
            $event->update(['status' => 'accepted', 'provider_message_id' => $sent->getMessageId(), 'accepted_at' => now()]);
        } catch (BrevoDeliveryException $exception) {
            $event->update(['status' => $exception->safeToRetry ? 'failed' : 'uncertain']);
        } catch (Throwable) {
            $event->update(['status' => 'uncertain']);
        }
    }

    public function retry(int $id): bool
    {
        if (ApplicationEmail::query()->whereKey($id)->where('status', 'failed')->update(['status' => 'pending']) !== 1) {
            return false;
        }
        $this->deliver($id);

        return true;
    }
}
