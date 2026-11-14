@props(['step' => 1, 'headingId' => 'registration-team-title'])
<div class="registration-panel">
    <aside class="registration-brand" aria-labelledby="{{ $headingId }}">
        <img class="registration-brand__photo" src="{{ asset('images/homepage/placeholders/hero.svg') }}" alt="" width="1920" height="1080">
        <div class="registration-brand__identity">
            <img src="{{ asset('images/branding/rlc-logo.png') }}" alt="RLCP emblem" width="300" height="300">
            <span><small>Risen Lord Church Phils., Inc.</small></span>
        </div>
        <div class="registration-brand__message">
            <h2 id="{{ $headingId }}">Join the<br><span>RLCP Team</span></h2>
            <p>Make a difference for children and families through faith, care, and community.</p>
        </div>
        <ol class="registration-steps" aria-label="Registration progress">
            @foreach (['Verify Email', 'Create Account', 'Director Review'] as $label)
                <li @if ($loop->iteration === (int) $step) aria-current="step" @endif @class(['is-complete' => $loop->iteration < (int) $step])>
                    <span class="registration-step-number" aria-hidden="true">{{ $loop->iteration < (int) $step ? '✓' : $loop->iteration }}</span>
                    <span>{{ $label }}@if ($loop->iteration < (int) $step)<span class="registration-sr-only"> — completed</span>@endif</span>
                </li>
            @endforeach
        </ol>
        <p class="registration-brand__footer">Rooted in faith. Growing together in care.</p>
    </aside>
    <div class="registration-content">{{ $slot }}</div>
</div>
