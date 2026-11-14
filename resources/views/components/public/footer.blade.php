@props(['landing' => false, 'pagesOnly' => false])
@php($homepageBase = $landing ? '' : route('home'))
<footer class="public-footer">
    <div class="public-container">
        <div class="public-footer__grid">
            <div class="public-footer__identity"><x-public.brand :href="$pagesOnly ? route('home') : $homepageBase.'#home'" /><p>Daraga, Albay, Philippines</p><p>Rooted in faith.<br>Growing together in care.</p></div>
            <nav aria-label="Footer navigation">
                <h2>Explore</h2>
                <a href="{{ $pagesOnly ? route('home') : $homepageBase.'#home' }}">Home</a>
                <a href="{{ route('about') }}" @if (request()->routeIs('about')) aria-current="page" @endif>About RLCP</a>
                <a href="{{ route('programs') }}" @if (request()->routeIs('programs')) aria-current="page" @endif>Programs</a>
                <a href="{{ route('public.activities.index') }}" @if (request()->routeIs('public.activities.index')) aria-current="page" @endif>Activities</a>
                @unless ($pagesOnly)
                    <a href="{{ $homepageBase }}#contact">Contact</a>
                @endunless
                <a href="{{ route('program-application') }}">Start an Application</a>
            </nav>
            @unless ($pagesOnly)
                <nav aria-label="Program navigation"><h2>Our Programs</h2><a href="{{ $homepageBase }}#education-learning">Education & Learning</a><a href="{{ $homepageBase }}#health-wellbeing">Health & Well-being</a><a href="{{ $homepageBase }}#faith-community">Faith & Community</a></nav>
            @endunless
            <nav aria-label="Staff access"><h2>For Staff</h2><a href="{{ route('login') }}" data-staff-login-open>Staff Login</a><a href="{{ route('register') }}" data-registration-open>Apply as Caseworker</a></nav>
        </div>
        <div class="public-footer__bottom"><p>Risen Lord Church Phils., Inc.</p><p>All for God’s glory. For children. For families. For a brighter Daraga, Albay.</p><a href="{{ $landing ? '#home' : '#page-top' }}">Back to top <x-public.icon name="arrow" /></a></div>
    </div>
</footer>
