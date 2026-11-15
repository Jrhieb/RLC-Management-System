<x-registration.page title="Verify Registration Email" :step="1">
        <h1 class="registration-title">Verification code</h1>
        <p class="registration-lead">Enter the six-digit code emailed to you. If your earlier code had eight digits, request a new code.</p>
        <form method="POST" action="{{ route('registration.code.verify') }}">
            @csrf
            <div class="registration-field">
                <label for="registration-code">Email verification code</label>
                <input id="registration-code" name="code" type="text" inputmode="numeric"
                    autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6"
                    required autofocus aria-describedby="registration-code-help registration-code-error"
                    @if ($errors->has('code')) aria-invalid="true" @endif>
            </div>
            <p id="registration-code-help" class="registration-help">The code expires after 30 minutes. You have five attempts. Use the browser where you requested the code.</p>
            <p id="registration-code-error" class="registration-error" role="alert">{{ $errors->first('code') }}</p>
            <button class="registration-submit" type="submit">Verify email</button>
        </form>
        <p class="registration-review-note">After verification, create your account and wait for Director approval.</p>
        <p class="registration-login"><a href="{{ route('register') }}">Request a new code or change email</a></p>
</x-registration.page>
