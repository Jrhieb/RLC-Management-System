<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Services\EmailRegistrationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RegistrationController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private EmailRegistrationService $registration) {}

    public function show(): View
    {
        return view('livewire.auth.register');
    }

    public function requestLink(Request $request): RedirectResponse|JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }

        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $request->session()->forget([EmailRegistrationService::SESSION_KEY, 'registration_completed']);
        $this->registration->requestLink($validated['email']);

        $message = 'If this email is available, a verification email has been queued. Check your inbox shortly.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 202);
        }

        return redirect()->route('register')->withInput($request->only('email'))
            ->with('status', $message);
    }

    public function verify(Request $request, string $token): RedirectResponse
    {
        $session = $this->registration->verify($token);

        if ($session === null) {
            return redirect()->route('register')->withErrors([
                'registration' => 'This verification link is invalid, expired, or already used. Request a new link.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->forget('registration_completed');
        $request->session()->put(EmailRegistrationService::SESSION_KEY, $session);

        return redirect()->route('registration.complete');
    }

    public function showComplete(Request $request): View|RedirectResponse
    {
        $registration = $this->registration->activeRequest($request->session()->get(EmailRegistrationService::SESSION_KEY));

        if ($registration === null) {
            return $this->invalidSession($request);
        }

        return view('livewire.auth.complete-registration', ['email' => $registration->email]);
    }

    public function complete(Request $request): RedirectResponse
    {
        $session = $request->session()->get(EmailRegistrationService::SESSION_KEY);

        if ($this->registration->activeRequest($session) === null) {
            return $this->invalidSession($request);
        }

        $validated = $request->validate([
            'name' => $this->nameRules(),
            'password' => $this->passwordRules(),
        ]);

        $this->registration->complete($session, $validated);
        $request->session()->forget(EmailRegistrationService::SESSION_KEY);
        $request->session()->regenerate();
        $request->session()->put('registration_completed', true);

        return redirect()->route('registration.awaiting-approval');
    }

    public function awaitingApproval(Request $request): View|RedirectResponse
    {
        return $request->session()->get('registration_completed') === true
            ? view('livewire.auth.registration-awaiting-approval')
            : redirect()->route('register');
    }

    private function invalidSession(Request $request): RedirectResponse
    {
        $request->session()->forget(EmailRegistrationService::SESSION_KEY);

        return redirect()->route('register')->withErrors(['registration' => EmailRegistrationService::INVALID_SESSION]);
    }
}
