<x-registration.page title="Complete Registration" :step="2">
    <p class="registration-eyebrow">One step closer</p>
    <h2 class="registration-title">Complete Registration</h2>
    <p class="registration-lead">Enter your name and choose a password.</p>
    <div class="registration-verified-email">
        <span>Verified email</span><strong>{{ $email }}</strong>
    </div>
    <p class="registration-help">Complete this step within 15 minutes of verification. Your account will require Director approval.</p>
    @error('registration')<p class="registration-error" role="alert">{{ $message }}</p>@enderror
    <form method="POST" action="{{ route('registration.complete.store') }}" data-registration-completion>
        @csrf
        <div class="registration-field">
            <label for="registration-name">Name</label>
            <input id="registration-name" name="name" type="text" required autofocus maxlength="255" autocomplete="name"
                value="{{ is_string(old('name')) ? old('name') : '' }}" placeholder="Full name"
                @error('name') aria-invalid="true" aria-describedby="registration-name-error" @enderror>
            @error('name')<p class="registration-error" id="registration-name-error" role="alert">{{ $message }}</p>@enderror
        </div>
        <x-registration.password-field name="password" label="Password" />
        @if (config('services.brevo.sms_enabled'))
            <div class="registration-field">
                <label for="registration-phone">Mobile number (optional)</label>
                <input id="registration-phone" name="phone_number" type="tel" maxlength="40" autocomplete="tel"
                    value="{{ is_string(old('phone_number')) ? old('phone_number') : '' }}" placeholder="+639171234567">
                <p class="registration-help">You can verify your phone after signing in. This is optional and does not replace email verification or Director approval.</p>
                @error('phone_number')<p class="registration-error" role="alert">{{ $message }}</p>@enderror
            </div>
        @endif
        <x-registration.password-field name="password_confirmation" label="Confirm password" />
        <button class="registration-submit" type="submit" data-test="complete-registration-button">Complete Registration <span class="registration-submit__arrow" aria-hidden="true">→</span></button>
    </form>
    <p class="registration-login"><a href="{{ route('register') }}">Request a new verification link</a></p>
</x-registration.page>
