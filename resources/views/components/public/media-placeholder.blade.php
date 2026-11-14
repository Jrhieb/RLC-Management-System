@props(['label'])
<div {{ $attributes->class(['public-media-placeholder']) }} role="img" aria-label="{{ $label }}. Photograph pending RLCP approval.">
    <x-public.icon name="image" />
    <strong>{{ $label }}</strong>
    <span>Photograph pending RLCP approval</span>
</div>
