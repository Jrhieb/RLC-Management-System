<x-layouts::app :title="__('Application details')">
    <div class="mx-auto max-w-5xl space-y-4">
        <flux:heading size="xl">Application details</flux:heading>
        <p>Reference: {{ $application->reference }}</p>
        <p>Status: {{ $application->status === 'pending_review' ? 'Pending Review' : $application->status }}</p>
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
                    <button type="submit">Record information needed</button>
                </form>
            @endif
        @endif
        @if (auth()->user()->isProjectDirector() && $application->status === 'for_decision')
            <form method="POST" action="{{ route('applications.decide', $application) }}">@csrf
                <label for="decision">Director decision</label><select id="decision" name="decision" required><option value="">Choose a decision</option><option value="approved">Approve</option><option value="waitlisted">Waitlist</option><option value="declined">Decline</option></select>
                <button type="submit">Record decision</button>
            </form>
            <p>Approval does not automatically enroll the applicant.</p>
        @endif
        <flux:link :href="auth()->user()->isProjectDirector() ? route('applications.index') : route('applications.assigned')">Back to applications</flux:link>
    </div>
</x-layouts::app>
