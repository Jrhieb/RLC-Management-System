<x-registration.page title="Registration complete" :step="3">
    <div class="registration-review-icon" aria-hidden="true">✓</div>
    <p class="registration-eyebrow">Thank you for taking the next step</p>
    <h2 class="registration-title">Registration complete</h2>
    <p class="registration-lead" role="status">Your account is awaiting Director approval.</p>
    <p class="registration-help">You can close this page. You have not been logged in.</p>
    <a class="registration-submit" href="{{ route('login') }}">Return to log in <span class="registration-submit__arrow" aria-hidden="true">→</span></a>
</x-registration.page>
