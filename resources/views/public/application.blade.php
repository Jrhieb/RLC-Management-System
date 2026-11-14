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
            <p>A proposed way for a parent, guardian, or family to request consideration for the Compassion-related program through RLCP.</p>
        </div>
        <div class="public-application__grid">
            <aside class="public-application__guide" aria-labelledby="application-guide-title">
                <span class="public-application__icon"><x-public.icon name="heart" /></span>
                <h2 id="application-guide-title">A request for consideration</h2>
                <p>A future submission would be a request for review. It would not guarantee enrollment, create a beneficiary record, or provide a beneficiary or staff account.</p>
                <ol>
                    <li><strong>Share only what is needed.</strong><span>This draft proposes guardian contact details only. Please do not enter real personal information.</span></li>
                    <li><strong>RLCP reviews the request.</strong><span>Eligibility, review steps, and response times still need RLCP approval.</span></li>
                    <li><strong>Enrollment is a separate decision.</strong><span>RLCP must confirm its process before online applications can open.</span></li>
                </ol>
                <a href="{{ route('programs') }}">Explore our programs <x-public.icon name="arrow" /></a>
            </aside>
            <section class="public-application__card" aria-labelledby="application-form-title">
                <p class="public-draft-badge">Draft preview · Applications are not open</p>
                <h2 id="application-form-title">Guardian contact form</h2>
                <p class="public-application__lead" id="application-draft-note">These proposed fields are awaiting RLCP approval. Use sample details to try the form. Nothing is sent, saved, or emailed.</p>
                {{-- No intake endpoint exists. Keep controls unnamed and disabled until the local-only preview handler is ready. --}}
                <form method="dialog" autocomplete="off" aria-describedby="application-draft-note application-privacy" data-application-draft>
                    <fieldset disabled>
                        <legend class="public-sr-only">Proposed guardian contact details — sample information only</legend>
                        <div class="public-application__field">
                            <label for="application-guardian">Parent / guardian name <span>(sample only)</span></label>
                            <input id="application-guardian" type="text" required maxlength="100" autocomplete="off" aria-describedby="application-guardian-error" data-draft-field="guardian">
                            <p id="application-guardian-error" class="public-field-error" data-draft-error="guardian"></p>
                        </div>
                        <div class="public-application__field">
                            <label for="application-contact">Contact number <span>(sample only)</span></label>
                            <input id="application-contact" type="tel" required maxlength="30" autocomplete="off" inputmode="tel" aria-describedby="application-contact-help application-contact-error" data-draft-field="contact">
                            <p id="application-contact-help" class="public-field-help">Use a sample number with 7–15 digits. Spaces, brackets, a plus sign, and hyphens are allowed.</p>
                            <p id="application-contact-error" class="public-field-error" data-draft-error="contact"></p>
                        </div>
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
                            <button class="public-button public-button--gold" type="submit">Check draft form <span class="public-button__arrow"><x-public.icon name="arrow" /></span></button>
                            <button type="reset" class="public-application__clear">Clear form</button>
                        </div>
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
