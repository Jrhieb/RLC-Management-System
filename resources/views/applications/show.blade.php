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
        <flux:link :href="route('applications.index')">Back to applications</flux:link>
    </div>
</x-layouts::app>
