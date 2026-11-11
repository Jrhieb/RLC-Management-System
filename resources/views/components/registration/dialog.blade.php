<dialog class="rlcp-registration rlcp-registration-dialog" id="caseworker-registration-dialog" aria-labelledby="registration-team-title" aria-describedby="registration-dialog-description" data-registration-dialog>
    <button class="registration-close" type="button" aria-label="Close registration" data-registration-close>×</button>
    <p class="registration-sr-only" id="registration-dialog-description">Begin Caseworker registration by verifying your email. Your account will require Director review.</p>
    <x-registration.panel><x-registration.email-form :embedded="true" /></x-registration.panel>
</dialog>
<div class="rlcp-registration registration-notice" data-registration-notice hidden>
    <p role="status" aria-live="polite" aria-atomic="true" data-registration-notice-text></p>
    <a href="{{ route('register') }}" data-registration-open>View registration request</a>
    <button type="button" aria-label="Dismiss request status" data-registration-notice-dismiss>×</button>
</div>
