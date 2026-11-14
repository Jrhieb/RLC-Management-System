<!DOCTYPE html>
<html lang="en" class="rlcp-public-document">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="About RLCP — a preview awaiting approved organization information and photographs.">
    <meta name="theme-color" content="#B91C1C">
    <meta name="robots" content="noindex, nofollow">
    <title>About RLCP | Risen Lord Church Phils., Inc.</title>
    <link rel="icon" href="{{ asset('images/branding/rlc-logo.png') }}" type="image/png">
    @fonts
    @vite(['resources/css/public-home.css', 'resources/js/public-home.js'])
</head>
<body class="rlcp-public public-about-page" id="page-top" data-public-home>
    <a class="public-skip" href="#main-content">Skip to content</a>
    <div class="about-header"><x-public.header /></div>
    <main id="main-content" tabindex="-1">
        <section class="about-hero" aria-labelledby="about-title">
            <div class="public-container">
                <div class="about-hero__frame">
                    <p class="about-hero__label"><x-public.icon name="image" /> Approved hero photo — placeholder · 3:1</p>
                    <h1 id="about-title">About RLCP</h1>
                    <p class="about-hero__note">Photograph pending RLCP approval</p>
                </div>
            </div>
        </section>

        <section class="public-section about-story" aria-labelledby="about-story-title">
            <div class="public-container">
                <div class="about-story__grid" data-public-reveal>
                    <div>
                        <p class="about-section-label">Our Story</p>
                        <h2 id="about-story-title"><span>Rooted in Faith.</span><br>Serving Our Community.</h2>
                        <span class="about-gold-rule" aria-hidden="true"></span>
                    </div>
                    <div class="about-story__notes">
                        <p><strong>Organization history</strong><span>Official history pending RLCP approval.</span></p>
                        <p><strong>Our milestones</strong><span>Official milestones pending RLCP approval.</span></p>
                    </div>
                </div>

                <div class="about-principles" aria-label="Mission, vision and values">
                    @foreach (['Mission' => 'church', 'Vision' => 'growth', 'Values' => 'community'] as $title => $icon)
                        <article class="about-principle" data-public-reveal>
                            <span class="about-principle__icon"><x-public.icon :name="$icon" /></span>
                            <div><h3>{{ $title }}</h3><p>Approved text placeholder.<br>Awaiting RLCP approval.</p></div>
                        </article>
                    @endforeach
                </div>

                <div class="about-media" aria-label="Community media placeholders" data-public-reveal>
                    <figure class="about-community-photo">
                        <div class="about-community-photo__art" role="img" aria-label="Community photo placeholder. No approved photograph has been supplied."><x-public.icon name="image" /></div>
                        <figcaption><strong>Community photo — placeholder · 3:2</strong><span>Approved photograph pending</span></figcaption>
                    </figure>
                    <aside class="about-video" aria-label="Optional video placeholder">
                        <x-public.icon name="image" />
                        <h3>Optional video</h3>
                        <p>Video, captions, and transcript pending RLCP approval.</p>
                    </aside>
                </div>

                <section class="about-journey" aria-labelledby="about-journey-title">
                    <div class="about-section-heading">
                        <p class="about-section-label">Our Journey</p>
                        <h2 id="about-journey-title"><span>Key</span> Milestones</h2>
                    </div>
                    <ol class="about-timeline" aria-label="Milestone placeholders">
                        @foreach (range(1, 3) as $position)
                            <li><span class="about-timeline__dot" aria-hidden="true"></span><p><x-public.icon name="calendar" /><span>Year and milestone pending approval.</span></p></li>
                        @endforeach
                    </ol>
                </section>
            </div>
        </section>

        <section class="public-section about-people" aria-labelledby="about-people-title">
            <div class="public-container">
                <div class="about-section-heading">
                    <p class="about-section-label">Our People</p>
                    <h2 id="about-people-title"><span>Our</span> People</h2>
                    <p class="about-section-note">Optional profiles — portraits and details awaiting approval.</p>
                </div>
                <div class="about-people__grid">
                    @foreach (range(1, 4) as $position)
                        <figure class="about-person" data-public-reveal>
                            <div class="about-person__portrait" role="img" aria-label="Portrait placeholder {{ $position }}. No approved portrait has been supplied."><x-public.icon name="person" /></div>
                            <figcaption><strong>Name and role pending approval.</strong><span class="about-gold-rule" aria-hidden="true"></span></figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>
    </main>
    <x-public.footer />
    <x-registration.dialog />
    <x-staff-login-dialog />
</body>
</html>
