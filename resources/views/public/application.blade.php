<!DOCTYPE html>
<html lang="en" class="rlcp-public-document">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="Content-Security-Policy" content="form-action 'self'">
    <title>Beneficiary Program Application | RLCP</title>
    <link rel="icon" href="{{ asset('images/branding/rlc-logo.png') }}" type="image/png">
    @fonts
    @vite(['resources/css/public-home.css', 'resources/js/public-home.js'])
</head>
<body class="rlcp-public public-application-page ppi-page" data-public-home>
    <a class="public-skip" href="#main-content">Skip to content</a>
    <x-public.header />
    <main id="main-content" tabindex="-1">
        <div class="ppi-hero">
            <div class="public-container">
                <h1>Apply for the <span>Beneficiary Program</span></h1>
                <p>Complete the household information and assessment form. No account is required.</p>
            </div>
        </div>
        <div class="ppi-layout public-container">
            <section class="ppi-card" aria-label="Beneficiary program application">
                @unless ($intakeOpen)
                    <p class="ppi-preview"><strong>Sample preview.</strong> Applications are not open. Use sample details. Nothing is sent, saved, or emailed.</p>
                @endunless
                <form method="{{ $intakeOpen ? 'post' : 'dialog' }}" @if ($intakeOpen) action="{{ route('ppi-applications.store', absolute: false) }}" @endif autocomplete="{{ $intakeOpen ? 'on' : 'off' }}" data-ppi-application>
                    @if ($intakeOpen)
                        @csrf
                        <input type="hidden" name="submission_key" value="{{ $submissionKey }}">
                    @endif
                    <ol class="ppi-steps" aria-label="Application steps">
                        @foreach (['Household Details', 'PPI Questions', 'Household Members', 'Review & Submit'] as $stepLabel)
                            <li @if ($loop->first) aria-current="step" @endif><span>{{ $loop->iteration }}</span>{{ $stepLabel }}</li>
                        @endforeach
                    </ol>
                    <fieldset disabled>
                        <legend class="public-sr-only">Program application</legend>
                        <section data-ppi-step="0">
                            <h2 tabindex="-1">Household Details</h2>
                            <p>Enter the child’s and guardian’s information. Fields marked with <span class="ppi-required">*</span> are required.</p>
                            @php
                                $groups = [
                                    'Applicant (Child)' => ['community', [
                                        ['child_first_name', 'First Name', 'text', 100, true, 'Enter first name'],
                                        ['child_middle_name', 'Middle Name', 'text', 100, false, 'Enter middle name'],
                                        ['child_last_name', 'Last Name', 'text', 100, true, 'Enter last name'],
                                        ['child_suffix', 'Suffix', 'select', 10, false, 'Choose (e.g., Jr., Sr.)', ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V']],
                                        ['child_sex', 'Sex', 'radio', 0, true, '', ['male' => 'Male', 'female' => 'Female']],
                                        ['child_date_of_birth', 'Date of Birth', 'date', 10, true, ''],
                                    ]],
                                    'Parent / Guardian Information' => ['home', [
                                        ['guardian_name', 'Parent / Guardian Name', 'text', 150, true, 'Enter full name'],
                                        ['guardian_relationship', 'Relationship to Child', 'select', 0, true, 'Choose relationship', ['Parent', 'Guardian', 'Other']],
                                        ['contact_number', 'Contact Number', 'tel', 32, true, 'Enter mobile number'],
                                        ['email', 'Email Address', 'email', 255, false, 'Enter email address'],
                                    ]],
                                    'Address' => ['pin', [
                                        ['barangay', 'Barangay', 'text', 255, true, 'Enter barangay'],
                                        ['city_municipality', 'City / Municipality', 'text', 255, true, 'Enter city or municipality'],
                                        ['complete_address', 'Complete Address (House No., Street, etc.)', 'text', 500, true, 'Enter complete address'],
                                    ]],
                                ];
                            @endphp
                            @foreach ($groups as $groupLabel => [$icon, $fields])
                                <h3 class="ppi-band"><x-public.icon :name="$icon" />{{ $groupLabel }}</h3>
                                <div class="ppi-fields">
                                    @foreach ($fields as $field)
                                        @php([$name, $label, $type, $max, $required, $placeholder] = $field)
                                        @if ($type === 'radio')
                                            <fieldset class="ppi-field" data-field-label="{{ $label }}">
                                                <legend>{{ $label }} <span class="ppi-required">*</span></legend>
                                                <div class="ppi-radio-row">
                                                    @foreach ($field[6] as $value => $option)
                                                        <label><input type="radio" name="{{ $name }}" value="{{ $value }}" required data-field-name="{{ $name }}"> {{ $option }}</label>
                                                    @endforeach
                                                </div>
                                                <p class="ppi-error" data-error-for="{{ $name }}" id="ppi-{{ $name }}-error" hidden></p>
                                            </fieldset>
                                        @else
                                            <div class="ppi-field @if ($name === 'complete_address') ppi-full @endif" data-field-label="{{ $label }}">
                                                <label for="ppi-{{ $name }}">{{ $label }} @if ($required)<span class="ppi-required">*</span>@else<span class="ppi-optional">(Optional)</span>@endif</label>
                                                @if ($type === 'select')
                                                    <select id="ppi-{{ $name }}" name="{{ $name }}" data-field-name="{{ $name }}" @required($required)>
                                                        <option value="">{{ $placeholder }}</option>
                                                        @foreach ($field[6] as $option)<option>{{ $option }}</option>@endforeach
                                                    </select>
                                                @else
                                                    <input id="ppi-{{ $name }}" name="{{ $name }}" type="{{ $type }}" data-field-name="{{ $name }}" @required($required)
                                                        @if ($type === 'date') min="1900-01-02" max="{{ today()->toDateString() }}" @else maxlength="{{ $max }}" placeholder="{{ $placeholder }}" @endif
                                                        >
                                                @endif
                                                <p class="ppi-error" data-error-for="{{ $name }}" id="ppi-{{ $name }}-error" hidden></p>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endforeach
                            <div class="ppi-actions"><button type="button" class="ppi-button ppi-next" data-ppi-next>Next: PPI Questions <x-public.icon name="arrow" /></button></div>
                        </section>
                        <section data-ppi-step="1" hidden>
                            <h2 tabindex="-1">PPI Questions</h2>
                            <p data-ppi-count aria-live="polite">0 of {{ count($questions) }} answered</p>
                            <progress class="ppi-progress" aria-label="Questions answered" value="0" max="{{ count($questions) }}"></progress>
                            @foreach ($questions as $question)
                                <fieldset class="ppi-question" data-ppi-question="{{ $question['id'] }}" @if (! $loop->first) hidden @endif>
                                    <legend tabindex="-1"><span>Question {{ $loop->iteration }} of {{ count($questions) }}</span>{{ $question['prompt'] }}</legend>
                                    @if ($question['guidance'])<p class="ppi-guidance">{{ $question['guidance'] }}</p>@endif
                                    @if (count($question['options']) > 8)
                                        <label class="public-sr-only" for="ppi-question-{{ $loop->iteration }}">Choose an answer</label>
                                        <select id="ppi-question-{{ $loop->iteration }}" data-ppi-answer required>
                                            <option value="">Choose an answer</option>
                                            @foreach ($question['options'] as $option)
                                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <div class="ppi-choices">
                                            @foreach ($question['options'] as $option)
                                                <label><input type="radio" name="question_{{ $question['id'] }}" value="{{ $option['id'] }}" data-ppi-answer required><span>{{ $option['label'] }}</span></label>
                                            @endforeach
                                        </div>
                                    @endif
                                </fieldset>
                            @endforeach
                            <div class="ppi-actions"><button type="button" class="ppi-secondary" data-ppi-back>Back</button><button type="button" class="ppi-button ppi-next" data-ppi-next>Next question</button></div>
                        </section>
                        <section data-ppi-step="2" hidden>
                            <h2 tabindex="-1">Household Members</h2>
                            <p>This step is optional. You can list household members or skip to review. Monthly income is optional.</p>
                            <div data-ppi-members></div>
                            <template data-ppi-member-template>
                                <fieldset class="ppi-member">
                                    <legend>Household member <span data-member-number></span></legend>
                                    <div class="ppi-fields">
                                        <label class="ppi-field">Name <span class="ppi-required">*</span><input data-member-field="name" maxlength="150" required></label>
                                        <label class="ppi-field">Relationship (Optional)<input data-member-field="relationship" maxlength="50"></label>
                                        <label class="ppi-field">Monthly income in pesos (Optional)<input data-member-field="monthly_income" type="number" inputmode="decimal" min="0" max="99999999.99" step="0.01"></label>
                                    </div>
                                    <button type="button" class="ppi-link" data-remove-member>Remove member</button>
                                </fieldset>
                            </template>
                            <button type="button" class="ppi-secondary ppi-add" data-add-member>Add member</button>
                            <div class="ppi-actions">
                                <button type="button" class="ppi-secondary" data-ppi-back>Back</button>
                                <button type="button" class="ppi-link" data-skip-members>Skip this step</button>
                                <button type="button" class="ppi-button ppi-next" data-ppi-next>Next: Review &amp; Submit</button>
                            </div>
                        </section>
                        <section data-ppi-step="3" hidden>
                            <h2 tabindex="-1">Review &amp; Submit</h2>
                            <p>Check your details before submitting the application.</p>
                            <div class="ppi-review">
                                @foreach (['Household details', 'PPI answers', 'Household members'] as $heading)
                                    <div class="ppi-review-header"><h3>{{ $heading }}</h3><button type="button" class="ppi-link" data-ppi-edit="{{ $loop->index }}">Edit {{ ['details', 'answers', 'members'][$loop->index] }}</button></div>
                                    <dl data-ppi-summary="{{ $loop->index }}"></dl>
                                @endforeach
                            </div>
                            <div class="ppi-guidance">
                                <h3>Privacy notice</h3>
                                @if ($intakeOpen)
                                    <p>{{ config('ppi.intake.retention_notice') }}</p>
                                    <p>Privacy contact: {{ config('ppi.intake.privacy_contact') }}</p>
                                @else
                                    <p>The live application will show RLCP’s privacy notice here.</p>
                                @endif
                                <label class="ppi-acknowledgment"><input type="checkbox" name="privacy_acknowledgment" value="1" required> {{ $intakeOpen ? config('ppi.intake.privacy_acknowledgment') : 'I acknowledge the sample privacy notice.' }}</label>
                            </div>
                            <div class="ppi-actions"><button type="button" class="ppi-secondary" data-ppi-back>Back</button><button type="submit" class="ppi-button ppi-next">{{ $intakeOpen ? 'Submit application' : 'Submit sample application' }}</button></div>
                        </section>
                    </fieldset>
                    <p class="ppi-error ppi-form-error" role="alert" tabindex="-1" data-ppi-error hidden></p>
                    <section class="ppi-result" aria-live="polite" data-ppi-result hidden>
                        <h2 tabindex="-1" data-result-heading></h2>
                        <p data-result-message></p>
                        <p class="ppi-reference" data-result-reference hidden></p>
                        <a class="ppi-secondary" href="{{ route('program-application') }}">{{ $intakeOpen ? 'Back to application page' : 'Try the preview again' }}</a>
                    </section>
                    <noscript><p>Enable JavaScript to use the application form.</p></noscript>
                </form>
            </section>
            <aside class="ppi-sidebar" aria-label="Application guide">
                <section class="ppi-about">
                    <h2><x-public.icon name="info" />About This Application</h2>
                    <p>This form uses the Poverty Probability Index (PPI) questionnaire to help RLCP identify families who may benefit from its programs.</p>
                </section>
                <section class="ppi-process">
                    <h2><x-public.icon name="clipboard" />Application Process</h2>
                    <ol>
                        <li><div><strong>Fill out household details</strong><p>Provide the child’s and guardian’s information.</p></div></li>
                        <li><div><strong>Answer the PPI questions</strong><p>Answer 10 questions about your household.</p></div></li>
                        <li><div><strong>List household members (optional)</strong><p>Add members or skip this step. Monthly income is optional.</p></div></li>
                        <li><div><strong>Review and submit</strong><p>Check your answers and submit your application.</p></div></li>
                    </ol>
                </section>
                <section class="ppi-reminders">
                    <h2><x-public.icon name="shield" />Important Reminders</h2>
                    <ul>
                        <li>Submit one application per household.</li>
                        <li>Answer all required questions.</li>
                        <li>Check that your email or mobile number is correct.</li>
                        <li>RLCP will use your contact details to send the result.</li>
                    </ul>
                </section>
            </aside>
        </div>
    </main>
    <x-public.footer />
    <x-registration.dialog />
    <x-staff-login-dialog />
</body>
</html>
