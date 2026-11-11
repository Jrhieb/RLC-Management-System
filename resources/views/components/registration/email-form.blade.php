@props(['embedded' => false])
<div data-registration-email data-has-requested="{{ session('status') ? 'true' : 'false' }}">
    <p class="registration-eyebrow">Become a Caseworker</p>
    <h2 class="registration-title">Create an account</h2>
    <p class="registration-lead">Verify your email to begin registration.</p>
    <form method="POST" action="{{ route('registration.request') }}" data-registration-form aria-busy="false">
        @csrf
        <div class="registration-field">
            <label for="registration-email">Email address</label>
            <input id="registration-email" name="email" type="email" required
                value="{{ is_string(old('email')) ? old('email') : '' }}"
                autocomplete="email" inputmode="email" maxlength="255" placeholder="email@example.com"
                aria-describedby="registration-email-help registration-email-error"
                @if ($errors->has('email')) aria-invalid="true" @endif
                @if (! $embedded) autofocus @endif>
        </div>
        <p class="registration-help" id="registration-email-help">We will queue a single-use link that expires 30 minutes after your request. Delivery may take a moment. Wait at least one minute before requesting another link. A new link replaces any earlier link or registration session.</p>
        <p class="registration-feedback" role="status" aria-live="polite" aria-atomic="true" data-registration-feedback>{{ session('status') }}</p>
        <p class="registration-error" id="registration-email-error" role="alert" aria-atomic="true" data-registration-error>{{ $errors->first('email') ?: $errors->first('registration') }}</p>
        <button class="registration-submit" type="submit" data-test="registration-email-button" aria-disabled="false">
            <span data-registration-idle>{{ session('status') ? 'Resend verification link' : 'Send verification link' }}</span>
            <span data-registration-loading hidden>Queuing verification email<span class="registration-loading-dots" aria-hidden="true"><span>.</span><span>.</span><span>.</span></span></span>
            <span aria-hidden="true" class="registration-submit__arrow">→</span>
        </button>
        <p class="registration-pending-note" data-registration-pending hidden>You can close this window. Your request will keep processing; closing does not cancel it.</p>
    </form>
    <p class="registration-review-note">Creating an account does not grant staff access. Every Caseworker account requires Director approval.</p>
    <p class="registration-login">Already have an account? <a href="{{ route('login') }}">Log In</a></p>
</div>
