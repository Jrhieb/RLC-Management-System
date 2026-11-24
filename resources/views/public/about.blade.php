<!DOCTYPE html>
<html lang="en" class="rlcp-public-document">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Get to know Risen Lord Church Phils., Inc. and see its community in photos.">
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
                    <img class="about-hero__photo" src="{{ asset('images/Image%20assets/499755501_122248739426015911_6605334484464686771_n.jpg') }}" alt="" fetchpriority="high">
                    <h1 id="about-title">About RLCP</h1>
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

                <div class="about-media" aria-label="Community photographs" data-public-reveal>
                    <figure class="about-community-photo">
                        <img class="about-community-photo__image" src="{{ asset('images/Image%20assets/475634194_1028374149331484_2893038508779317236_n.jpg') }}" alt="RLCP community members gathered indoors" loading="lazy" decoding="async">
                    </figure>
                    <figure class="about-video about-media-photo"><img src="{{ asset('images/Image%20assets/475819601_1032137408955158_7026391996698759231_n.jpg') }}" alt="RLCP community gathering outdoors" loading="lazy" decoding="async"></figure>
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
                </div>
                <div class="about-people__grid">
                    @foreach (['471950061_122219733956015911_7061962689076303098_n.jpg', '475645835_1028374385998127_9134754074080059052_n.jpg', '476833248_1035851791917053_485477017824102922_n.jpg', '499773338_122248801982015911_8301704947418968676_n.jpg'] as $photo)
                        <figure class="about-person" data-public-reveal>
                            <img class="about-person__portrait" src="{{ asset('images/Image%20assets/' . rawurlencode($photo)) }}" alt="RLCP community gathering" loading="lazy" decoding="async">
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
