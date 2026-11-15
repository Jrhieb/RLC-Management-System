<?php

namespace App\Http\Controllers;

use App\Services\PhoneVerificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PhoneVerificationController extends Controller
{
    public function __construct(private PhoneVerificationService $verification) {}

    public function show(Request $request): View
    {
        abort_unless(config('services.brevo.sms_enabled'), 404);

        return view('phone-verification', ['account' => $request->user()->fresh()]);
    }

    public function save(Request $request): RedirectResponse
    {
        abort_unless(config('services.brevo.sms_enabled'), 404);
        $input = $request->validate(['phone_number' => ['nullable', 'string', 'max:40']]);
        $this->verification->savePhone($request->user(), $input['phone_number'] ?? null);

        return redirect()->route('account.phone.show')->with('status', 'Phone saved. Verification is optional; saving does not send an SMS.');
    }

    public function send(Request $request): RedirectResponse
    {
        $this->verification->send($request->user(), (string) $request->ip());

        return redirect()->route('account.phone.show')->with('status', 'SMS accepted for sending. Check your phone; delivery may be delayed.');
    }

    public function verify(Request $request): RedirectResponse
    {
        abort_unless(config('services.brevo.sms_enabled'), 404);
        $input = $request->validate(['phone_code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);
        if (! $this->verification->verify($request->user(), $input['phone_code'])) {
            return redirect()->route('account.phone.show')->withErrors(['phone_code' => 'The code is invalid, expired, already used, or has too many attempts.']);
        }

        return redirect()->route('account.phone.show')->with('status', 'Your phone number is verified.');
    }
}
