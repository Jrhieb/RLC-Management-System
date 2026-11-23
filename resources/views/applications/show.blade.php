<x-layouts::app :title="__('Application details')">
    <div class="mx-auto max-w-5xl space-y-4">
        <flux:heading size="xl">Application details</flux:heading>
        <p>Reference: {{ $application->contactReference() }}</p>
        @if ($application->ppiApplication && auth()->user()->can('view', $application->ppiApplication))
            <a class="underline" href="{{ route('ppi-applications.show', $application->ppiApplication) }}">View PPI application</a>
        @endif
        <p>Status: {{ ucwords(str_replace('_', ' ', $application->status)) }}</p>
        @if ($application->status === 'approved' && auth()->user()->isProjectDirector())
            <flux:link :href="route('applications.enrollment.create', $application)">Enroll beneficiary</flux:link>
        @elseif ($canViewBeneficiary)
            <flux:link :href="route('beneficiaries.show', $beneficiary)">View enrolled beneficiary</flux:link>
        @endif
        <dl class="space-y-2">
            <dt>Parent or guardian</dt><dd>{{ $application->guardian_name }}</dd>
            <dt>Contact number</dt><dd>{{ $application->contact_number }}</dd>
            <dt>Email</dt><dd>{{ $application->email ?? 'Not provided' }}</dd>
            <dt>Barangay</dt><dd>{{ $application->barangay }}</dd>
            <dt>City or municipality</dt><dd>{{ $application->municipality }}</dd>
            <dt>Reason for applying</dt><dd>{{ $application->reason }}</dd>
        </dl>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if ($application->assessment_findings)
            <h2>Assessment findings</h2><p>{{ $application->assessment_findings }}</p>
            <h2>Recommendation</h2><p>{{ $application->assessment_recommendation }}</p>
        @endif
        @if ($application->information_needed)
            <h2>Information needed</h2><p>{{ $application->information_needed }}</p>
        @endif
        @if (auth()->user()->isProjectDirector() && $application->status === 'pending_review')
            <form method="POST" action="{{ route('applications.review', $application) }}">@csrf<button type="submit">Start review</button></form>
        @endif
        @if (auth()->user()->isProjectDirector() && in_array($application->status, ['pending_review', 'under_review', 'for_assessment', 'needs_information'], true))
            <form method="POST" action="{{ route('applications.assign', $application) }}">@csrf
                <label for="caseworker_id">Assign an approved Caseworker</label>
                <select id="caseworker_id" name="caseworker_id" required><option value="">Choose a Caseworker</option>@foreach ($caseworkers as $caseworker)<option value="{{ $caseworker->id }}">{{ $caseworker->name }}</option>@endforeach</select>
                <button type="submit">Assign for assessment</button>
            </form>
        @endif
        @if (auth()->user()->isEligibleCaseworker() && $application->assigned_caseworker_id === auth()->id() && in_array($application->status, ['for_assessment', 'needs_information'], true))
            <form method="POST" action="{{ route('applications.assess', $application) }}">@csrf
                <label for="findings">Assessment findings</label><textarea id="findings" name="findings" required maxlength="10000">{{ old('findings') }}</textarea>
                <label for="recommendation">Recommendation</label><textarea id="recommendation" name="recommendation" required maxlength="5000">{{ old('recommendation') }}</textarea>
                <button type="submit">Submit assessment</button>
            </form>
            @if ($application->status === 'for_assessment')
                <form method="POST" action="{{ route('applications.information', $application) }}">@csrf
                    <label for="information_findings">Information needed</label><textarea id="information_findings" name="findings" required maxlength="10000"></textarea>
                    @if ($application->preferred_contact === 'email')
                        <label for="information_applicant_message">Information requested and how to respond (sent to applicant)</label><textarea id="information_applicant_message" name="applicant_message" required maxlength="3000">{{ old('applicant_message') }}</textarea>
                    @endif
                    <button type="submit">Record information needed</button>
                </form>
            @endif
        @endif
        @if (auth()->user()->isProjectDirector() && $application->status === 'for_decision')
            <form method="POST" action="{{ route('applications.decide', $application) }}">@csrf
                <label for="decision">Director decision</label><select id="decision" name="decision" required><option value="">Choose a decision</option><option value="approved">Approve</option><option value="waitlisted">Waitlist</option><option value="declined">Decline</option></select>
                @if ($application->preferred_contact === 'email')
                    <label for="decision_applicant_message">Applicant-facing explanation or next steps (required for approval or decline)</label><textarea id="decision_applicant_message" name="applicant_message" maxlength="3000">{{ old('applicant_message') }}</textarea>
                @endif
                <button type="submit">Record decision</button>
            </form>
            <p>Approval does not automatically enroll the applicant.</p>
        @endif
        <section aria-labelledby="appointments-title">
            <h2 id="appointments-title">Assessment appointments</h2>
            @foreach ($appointments as $appointment)
                <p>{{ \Illuminate\Support\Carbon::parse($appointment->scheduled_at, 'UTC')->timezone('Asia/Manila')->format('M d, Y g:i A') }} — {{ $appointment->location_or_method }}</p>
                <p>{{ $appointment->instructions }}</p>
            @endforeach
            <form method="POST" action="{{ route('applications.appointments.store', $application) }}">@csrf
                <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <label for="scheduled_at">Confirmed date and time (Philippine time)</label><input id="scheduled_at" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}" required>
                <label for="location_or_method">Location or method</label><input id="location_or_method" name="location_or_method" value="{{ old('location_or_method') }}" maxlength="255" required>
                <label for="appointment_instructions">Applicant instructions</label><textarea id="appointment_instructions" name="instructions" maxlength="3000" required>{{ old('instructions') }}</textarea>
                <label><input type="checkbox" name="confirmed" value="1" required> I confirm these appointment details.</label>
                <button type="submit">Save confirmed appointment</button>
            </form>
        </section>
        <section aria-labelledby="email-history-title">
            <h2 id="email-history-title">Applicant email status</h2>
            @forelse ($emails as $email)
                <p>{{ $email->subject }}: {{ ucfirst($email->status) }} @if ($email->status === 'accepted') ({{ $email->delivery_confirmed_at ? 'recipient-reported receipt recorded' : 'accepted by provider; delivery not confirmed' }}) @elseif (in_array($email->status, ['uncertain', 'sending'], true)) (check the provider before taking further action) @endif</p>
                @if ($email->status === 'failed')
                    <form method="POST" action="{{ route('applications.emails.retry', [$application, $email->id]) }}">@csrf<button type="submit">Retry email</button></form>
                @endif
                @if ($email->status === 'accepted' && ! $email->delivery_confirmed_at)
                    <form method="POST" action="{{ route('applications.emails.receipt', [$application, $email->id]) }}">@csrf<label><input type="checkbox" name="recipient_confirmed" value="1" required> The recipient confirmed they received this email.</label><button type="submit">Record receipt</button></form>
                @endif
            @empty <p>No applicant emails recorded.</p> @endforelse
        </section>
        <section aria-labelledby="contact-log-title">
            <h2 id="contact-log-title">Private contact history</h2>
            @forelse ($contacts as $contact)
                <article>
                    <p>{{ \Illuminate\Support\Carbon::parse($contact->contact_at, 'UTC')->timezone('Asia/Manila')->format('M d, Y g:i A') }} · {{ $contact->actor_name ?? 'Former staff member' }}</p>
                    <p>{{ str_replace('_', ' ', ucfirst($contact->method)) }} · {{ str_replace('_', ' ', ucfirst($contact->outcome)) }}</p>
                    <p>{{ $contact->summary }}</p>
                    @if ($contact->follow_up_on)<p>Follow-up: {{ $contact->follow_up_on }}</p>@endif
                    @if ($contact->private_notes)<p>Private notes: {{ $contact->private_notes }}</p>@endif
                </article>
            @empty <p>No contacts recorded.</p> @endforelse
            <form method="POST" action="{{ route('applications.contacts.store', $application) }}">@csrf
                <h3>Record a manual contact</h3>
                <p>Do not include passwords, verification codes, reset links, call recordings, or unnecessary sensitive information.</p>
                <label for="contact_at">Contact date and time (Philippine time)</label><input id="contact_at" name="contact_at" type="datetime-local" required value="{{ old('contact_at') }}">
                <label for="contact_method">Method</label><select id="contact_method" name="method" required><option value="">Choose method</option><option value="phone">Phone call</option><option value="in_person">In person</option><option value="manual_email">Manual email</option></select>
                <label for="contact_outcome">Outcome</label><select id="contact_outcome" name="outcome" required><option value="">Choose outcome</option><option value="reached">Reached</option><option value="no_answer">No answer</option><option value="unreachable">Unreachable / invalid contact</option><option value="follow_up_needed">Follow-up needed</option></select>
                <label for="contact_summary">Brief summary</label><textarea id="contact_summary" name="summary" maxlength="1000" required>{{ old('summary') }}</textarea>
                <label for="follow_up_on">Next follow-up date (optional)</label><input id="follow_up_on" name="follow_up_on" type="date" value="{{ old('follow_up_on') }}">
                <label for="contact_notes">Private staff notes (optional)</label><textarea id="contact_notes" name="private_notes" maxlength="5000">{{ old('private_notes') }}</textarea>
                <button type="submit">Record contact</button>
            </form>
        </section>
        <flux:link :href="auth()->user()->isProjectDirector() ? route('applications.index') : route('applications.assigned')">Back to applications</flux:link>
    </div>
</x-layouts::app>
