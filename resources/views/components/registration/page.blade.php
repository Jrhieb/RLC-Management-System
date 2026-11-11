@props(['title', 'step' => 1])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} | RLCP</title>
    <link rel="icon" href="{{ asset('images/branding/rlc-logo.png') }}" type="image/png">
    @fonts
    @vite(['resources/css/registration.css', 'resources/js/registration-ui.js'])
</head>
<body class="registration-page">
    <main class="rlcp-registration registration-page__main">
        <a class="registration-back" href="{{ route('home') }}">← Back to RLCP</a>
        <h1 class="registration-sr-only">{{ $title }}</h1>
        <x-registration.panel :step="$step">{{ $slot }}</x-registration.panel>
    </main>
</body>
</html>
