@props(['landing' => false, 'pagesOnly' => false])
@php($publicPages = ['about' => 'about', 'programs' => 'programs', 'activities' => 'public.activities.index'])
<header class="public-header public-container" data-public-header>
    <x-public.brand :href="$landing ? '#home' : route('home')" />
    <button class="public-menu-toggle" type="button" aria-controls="public-navigation" aria-expanded="false" hidden data-menu-toggle>
        <span data-menu-label>Menu</span><x-public.icon name="menu" />
    </button>
    <nav class="public-navigation" id="public-navigation" aria-label="Main navigation" data-public-navigation>
        @foreach (['home' => 'Home', 'about' => 'About', 'programs' => 'Programs', 'activities' => 'Activities', 'contact' => 'Contact'] as $section => $label)
            @continue($pagesOnly && $section === 'contact')
            <a href="{{ isset($publicPages[$section]) ? route($publicPages[$section]) : ($section === 'home' && $pagesOnly ? route('home') : ($landing ? '' : route('home')).'#'.$section) }}" @if (isset($publicPages[$section]) && request()->routeIs($publicPages[$section])) aria-current="page" @elseif ($landing && $section === 'home') aria-current="location" @endif>{{ $label }}</a>
        @endforeach
        <button class="public-theme-toggle" type="button" aria-pressed="false" hidden data-public-theme-toggle>
            <span class="public-sr-only">Dark theme</span>
            <span class="public-theme-toggle__moon"><x-public.icon name="moon" /></span>
            <span class="public-theme-toggle__sun"><x-public.icon name="sun" /></span>
        </button>
        <a class="public-button public-nav-login" href="{{ route('login') }}" data-staff-login-open>Log In</a>
        <a class="public-button public-button--gold public-nav-apply" href="{{ route('program-application') }}" @if (request()->routeIs('program-application')) aria-current="page" @endif>Start an Application</a>
    </nav>
</header>
