<!DOCTYPE html>
<html lang="en" class="rlcp-public-document">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Preview RLCP’s proposed family application form. Online applications are not yet open.">
    <meta name="theme-color" content="#B91C1C">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="Content-Security-Policy" content="form-action 'self'">
    <title>Program Application — Draft Preview | RLCP</title>
    <link rel="icon" href="{{ asset('images/branding/rlc-logo.png') }}" type="image/png">
    @fonts
    @vite(['resources/css/public-home.css', 'resources/js/public-home.js'])
</head>
<body class="rlcp-public public-application-page" data-public-home>
    <a class="public-skip" href="#main-content">Skip to content</a>
    <x-public.header />
    <main id="main-content" tabindex="-1" class="public-application public-container">
        <div class="public-application__intro">
            <p class="public-eyebrow">Growing together through care</p>
            <h1>A hopeful first step.<br><span class="public-warm-text">Let’s begin with care.</span></h1>
            <p>Applications are not open yet. This preview does not send or save information. No account is required.</p>
        </div>
        <div class="public-application__grid">
            <aside class="public-application__guide" aria-labelledby="application-guide-title">
                <span class="public-application__icon"><x-public.icon name="heart" /></span>
                <h2 id="application-guide-title">What happens next</h2>
                <p>A future submission would be a request for review. It would not guarantee enrollment, create a beneficiary record, or provide a beneficiary or staff account.</p>
                <ol>
                    <li><strong>Submit a request.</strong><span>Online applications are not open yet.</span></li>
                    <li><strong>Staff review and assessment.</strong><span>Staff review the request and arrange an assessment.</span></li>
                    <li><strong>Director decision.</strong><span>If approved, enrollment is a separate step. Acceptance and sponsorship are not guaranteed.</span></li>
                </ol>
                <a href="{{ route('programs') }}">Explore our programs <x-public.icon name="arrow" /></a>
            </aside>
            <section class="public-application__card" aria-labelledby="application-form-title">
                <p class="public-draft-badge">Draft preview · Applications are not open</p>
                <h2 id="application-form-title">Guardian contact form</h2>
                <p class="public-application__lead" id="application-draft-note">These proposed fields are awaiting RLCP approval. Use sample details to try the form. Nothing is sent, saved, or emailed.</p>
                <ol class="application-steps" aria-label="Preview steps"><li data-step-indicator="1" aria-current="step">1. Contact details</li><li data-step-indicator="2">2. Application</li><li data-step-indicator="3">3. Review</li></ol>
                {{-- No intake endpoint exists. Keep controls unnamed and disabled until the local-only preview handler is ready. --}}
                <form method="dialog" autocomplete="off" aria-describedby="application-draft-note application-privacy" data-application-draft>
                    <fieldset disabled>
                        <legend class="public-sr-only">Proposed guardian contact details — sample information only</legend>
                        <section data-step="1" tabindex="-1" aria-label="Contact details">
                        <div class="public-application__field">
                            <label for="application-guardian">Parent / guardian name <span>(sample only)</span></label>
                            <input id="application-guardian" type="text" required maxlength="100" autocomplete="off" aria-describedby="application-guardian-error" data-draft-field="guardian">
                            <p id="application-guardian-error" class="public-field-error" data-draft-error="guardian"></p>
                        </div>
                        <div class="public-application__field">
                            <label for="application-relationship">Relationship to child</label>
                            <select id="application-relationship" required><option value="">Choose relationship</option><option>Parent</option><option>Guardian</option><option>Other</option></select>
                        </div>
                        <div class="public-application__field">
                            <label for="application-email">Email address</label>
                            <input id="application-email" type="email" autocomplete="off" data-preview-email>
                        </div>
                        <div class="public-application__field">
                            <label for="application-barangay">Barangay</label>
                            <input id="application-barangay" type="text" autocomplete="off" data-preview-required>
                        </div>
                        <div class="public-application__field">
                            <label for="application-city">City / municipality</label>
                            <input id="application-city" type="text" autocomplete="off" data-preview-required>
                        </div>
                        <div class="public-application__field">
                            <label for="application-method">Preferred contact method</label>
                            <select id="application-method" data-preview-method><option value="phone">Phone call</option><option value="email">Email</option><option disabled>SMS (unavailable)</option></select>
                        </div>
                        <div class="public-application__field">
                            <label for="application-contact">Contact number <span>(sample only)</span></label>
                            <input id="application-contact" type="tel" required maxlength="30" autocomplete="off" inputmode="tel" aria-describedby="application-contact-help application-contact-error" data-draft-field="contact">
                            <p id="application-contact-help" class="public-field-help">Use a sample number with 7–15 digits. Spaces, brackets, a plus sign, and hyphens are allowed.</p>
                            <p id="application-contact-error" class="public-field-error" data-draft-error="contact"></p>
                        </div>
                        <button type="button" class="public-button public-button--gold" data-next>Continue to application</button>
                        </section>
                        <section data-step="2" tabindex="-1" aria-label="Application" hidden>
                            <div class="public-application__field"><label for="application-reason">Brief reason for applying</label><textarea id="application-reason" rows="4" required></textarea></div>
                            <button type="button" class="public-application__clear" data-back>Back</button>
                            <button type="button" class="public-button public-button--gold" data-next>Review answers</button>
                        </section>
                        <section data-step="3" tabindex="-1" aria-label="Review" hidden>
                        <h3>Review sample answers</h3><dl data-review></dl>
                        <button type="button" class="public-application__clear" data-edit="1">Edit contact details</button>
                        <button type="button" class="public-application__clear" data-edit="2">Edit application</button>
                        <div class="public-application__privacy" id="application-privacy">
                            <h3>Privacy in this preview</h3>
                            <p>Entries stay on this page while it is open. This form does not upload, store, or transmit them. Do not include a child’s details, identity documents, health information, or other sensitive information.</p>
                            <p>Before accepting applications, RLCP must approve the information collected, consent wording, who may review it, retention and deletion periods, and a privacy contact.</p>
                        </div>
                        <label class="public-application__consent" for="application-consent">
                            <input id="application-consent" type="checkbox" required aria-describedby="application-consent-error" data-draft-field="consent">
                            <span>I understand this is a draft preview and I am using sample information only. This is not consent to submit a real application.</span>
                        </label>
                        <p id="application-consent-error" class="public-field-error" data-draft-error="consent"></p>
                        <div class="public-application__actions">
                            <button class="public-button public-button--gold" type="submit">Complete sample preview <span class="public-button__arrow"><x-public.icon name="arrow" /></span></button>
                            <button type="reset" class="public-application__clear">Clear form</button>
                        </div>
                        </section>
                    </fieldset>
                    <p class="public-application__status" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" data-draft-status></p>
                    <noscript><p class="public-application__privacy">JavaScript is needed to try local validation. This draft cannot accept or send an application.</p></noscript>
                </form>
            </section>
        </div>
    </main>
    <footer class="public-footer public-application__footer">
        <div class="public-container">
            <x-public.brand :href="route('home')" />
            <p>Rooted in faith. Growing together in care.</p>
            <nav aria-label="Application footer"><a href="{{ route('home') }}">Back to RLCP</a><a href="{{ route('home') }}#contact">Contact information</a><a href="{{ route('login') }}" data-staff-login-open>Staff Log In</a></nav>
        </div>
    </footer>
    <x-registration.dialog />
    <x-staff-login-dialog />
</body>
</html>
