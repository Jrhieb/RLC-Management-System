@props(['beneficiary'])
@php
    $initials = \Illuminate\Support\Str::of($beneficiary->full_name)->trim()->explode(' ')
        ->filter()->take(2)->map(fn ($part) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))->implode('');
@endphp
<div class="rlcr-person">
    <div class="rlcr-avatar" x-data="{ failed: false }">
        @if ($beneficiary->photo_path)
            <img src="{{ route('beneficiaries.photo', $beneficiary) }}" alt="" loading="lazy" width="40" height="40"
                 x-show="!failed" x-on:error="failed = true">
            <span x-cloak x-show="failed">{{ $initials }}</span>
        @else
            <span>{{ $initials }}</span>
        @endif
    </div>
    <div class="rlcr-person-name">
        @can('view', $beneficiary)
            <a href="{{ route('beneficiaries.show', $beneficiary) }}">{{ $beneficiary->full_name }}</a>
        @else
            <strong>{{ $beneficiary->full_name }}</strong>
        @endcan
    </div>
</div>
