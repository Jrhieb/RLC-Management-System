<?php

namespace App\Http\Controllers;

use App\Models\BeneficiaryApplication;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicApplicationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless(config('services.application_intake.enabled') === true && filled(config('services.application_intake.privacy_contact')) && filled(config('services.application_intake.privacy_acknowledgment')) && filled(config('services.application_intake.retention_notice')), 403);
        $data = $request->validate([
            'submission_key' => ['required', 'uuid'],
            'guardian_name' => ['required', 'string', 'max:100'],
            'guardian_relationship' => ['required', 'in:Parent,Guardian,Other'],
            'contact_number' => ['required', 'string', 'max:32', 'regex:/^(?=(?:\D*\d){7,15}\D*$)[+0-9()\s-]+$/'],
            'preferred_contact' => ['required', 'in:phone,email'],
            'email' => ['nullable', 'required_if:preferred_contact,email', 'email', 'max:255'],
            'barangay' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:2000'],
            'acknowledgment' => ['accepted'],
        ]);
        $sessionKey = $request->session()->get('application_submission_key');
        abort_unless(is_string($sessionKey) && hash_equals($sessionKey, $data['submission_key']), 403);
        try {
            $application = BeneficiaryApplication::submit($data);
        } catch (UniqueConstraintViolationException $exception) {
            $application = BeneficiaryApplication::where('submission_key', $data['submission_key'])->first();
            if (! $application) {
                throw $exception;
            }
        }

        return response()->json(['reference' => $application->reference, 'message' => 'Your application has been received for review. Submission does not guarantee acceptance. Our team will contact you about the next steps.'])->header('Cache-Control', 'private, no-store');
    }
}
