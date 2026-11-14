<!DOCTYPE html>
<html lang="en" class="rlcp-public-document">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Risen Lord Church Phils., Inc. — growing in faith and supporting children and families through learning and community care in Daraga, Albay.">
    <meta name="theme-color" content="#B91C1C">
    <title>RLCP | Growing in Faith. Building Brighter Futures.</title>
    <link rel="icon" href="{{ asset('images/branding/rlc-logo.png') }}" type="image/png">
    @fonts
    @vite(['resources/css/public-home.css', 'resources/js/public-home.js'])
</head>
<body class="rlcp-public" data-public-home>
    {{-- Public presentation only. Draft copy and replacement images: docs/public-homepage.md.
         Never use operational activities, beneficiary records, or private files here. --}}
    <a class="public-skip" href="#main-content">Skip to content</a>
    <x-public.header :landing="true" />
    <main id="main-content" tabindex="-1">
        <section class="public-hero" id="home" aria-labelledby="hero-title">
            <img class="public-hero__image" src="{{ asset('images/homepage/placeholders/Herobanner.png') }}" alt="" width="1920" height="1280" fetchpriority="high">
            <div class="public-hero__content public-container">
                <p class="public-eyebrow public-eyebrow--hero">Faith <span>•</span> Care <span>•</span> Community</p>
                <h1 id="hero-title">Growing in Faith.<br><span>Building Brighter Futures.</span></h1>
                <p class="public-hero__intro">Supporting children and families through faith, learning, and community care.</p>
                <div class="public-actions">
                    <a class="public-button public-button--gold" href="{{ route('programs') }}">Explore Our Programs <span class="public-button__arrow"><x-public.icon name="arrow" /></span></a>
                    <a class="public-button public-button--outline" href="{{ route('about') }}">About RLCP <span class="public-button__arrow"><x-public.icon name="arrow" /></span></a>
                </div>
            </div>
        </section>

        <section class="public-section public-about" id="about" aria-labelledby="about-title">
            <div class="public-container">
                <div class="public-about__grid">
                    <div class="public-collage" data-public-reveal>
                        <img class="public-collage__rectangle" src="{{ asset('images/homepage/placeholders/learning.png') }}" alt="Learning activities" width="960" height="640" loading="lazy" decoding="async">
                        <img class="public-collage__circle" src="{{ asset('images/homepage/placeholders/family.jpg') }}" alt="Family engagement" width="640" height="640" loading="lazy" decoding="async">
                        <span class="public-faith-seal">Rooted<br>in Faith<span aria-hidden="true"></span></span>
                    </div>
                    <div class="public-about__copy" data-public-reveal>
                        <p class="public-eyebrow">About RLCP</p>
                        <h2 id="about-title">A Community That Cares,<br><span class="public-warm-text">A Future Filled With Hope</span></h2>
                        <div class="public-tabs" data-about-tabs>
                            <nav class="public-tab-links" aria-label="About RLCP" data-tab-list>
                                <a href="#our-story" id="tab-story" data-about-tab>Our Story</a>
                                <a href="#our-mission" id="tab-mission" data-about-tab>Our Mission</a>
                                <a href="#our-vision" id="tab-vision" data-about-tab>Our Vision</a>
                            </nav>
                            <div class="public-tab-panel" id="our-story" data-about-panel>
                                <h3 class="public-tab-fallback-heading">Our Story</h3>
                                <p>Risen Lord Church Phils., Inc. (RLCP) is a community church in Daraga, Albay committed to supporting children and families through faith, learning, and community care. We believe in the transformative power of God’s love to build stronger families and brighter futures for the next generation.</p>
                            </div>
                            <div class="public-tab-panel" id="our-mission" data-about-panel>
                                <h3 class="public-tab-fallback-heading">Our Mission</h3>
                                <p>To walk alongside children and families through faith, learning, and community care — nurturing growth, encouraging well-being, and sharing God’s love in everyday life.</p>
                            </div>
                            <div class="public-tab-panel" id="our-vision" data-about-panel>
                                <h3 class="public-tab-fallback-heading">Our Vision</h3>
                                <p>A caring community where children can learn, families can flourish, and hope can grow. Together, we look toward a brighter future rooted in faith and compassion.</p>
                            </div>
                        </div>
                        <a class="public-button public-button--red" href="#values">What Guides Us <span class="public-button__arrow"><x-public.icon name="arrow" /></span></a>
                    </div>
                </div>
                <div class="public-values" id="values" role="group" aria-label="Our values">
                    @foreach ([
                        ['faith', 'Faith', 'Nurturing spiritual growth and godly values.'],
                        ['learning', 'Learning', 'Equipping children and families with knowledge and life skills.'],
                        ['heart', 'Well-being', 'Supporting healthier, stronger families.'],
                        ['community', 'Community', 'Building a caring and inclusive community in Daraga, Albay.'],
                    ] as [$icon, $title, $description])
                        <div class="public-value" data-public-reveal>
                            <span class="public-value__icon public-value__icon--{{ $icon }}"><x-public.icon :name="$icon" /></span>
                            <h3>{{ $title }}</h3><p>{{ $description }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="public-section public-programs" id="programs" aria-labelledby="programs-title">
            <div class="public-container">
                <div class="public-section-heading" data-public-reveal>
                    <div><p class="public-eyebrow">Our Programs</p><h2 id="programs-title">Supporting Every Step<br>of <span>Their Growth</span></h2></div>
                    <p>Faith-filled programs for stronger children, brighter families, and a more hopeful community.</p>
                </div>
                <div class="public-card-grid">
                    @foreach ([
                        ['education-learning', 'learning.png', 'Education & Learning', 'Creating learning opportunities for a brighter tomorrow.', 'A space to learn, build confidence, and develop everyday skills, with care and encouragement along the way.'],
                        ['health-wellbeing', 'wellbeing.jpg', 'Health & Well-being', 'Promoting healthy and happy children and families.', 'Care begins with well-being. We encourage healthy habits, kindness, and supportive connections for children and their families.'],
                        ['faith-community', 'fellowship.jpg', 'Faith & Community', 'Building strong families through faith and fellowship.', 'Shared faith brings us together. Through fellowship and community life, we make room for connection, encouragement, and spiritual growth.'],
                    ] as [$id, $photo, $title, $description, $detail])
                        <article class="public-program-card" id="{{ $id }}" data-public-reveal>
                            <img src="{{ asset('images/homepage/placeholders/'.$photo) }}" alt="{{ $title }}" width="960" height="640" loading="lazy" decoding="async">
                            <div class="public-program-card__body">
                                <h3>{{ $title }}</h3><p>{{ $description }}</p>
                                <details class="public-program-detail">
                                    <summary>Our approach<span class="public-card-arrow"><x-public.icon name="arrow" /></span><span class="public-sr-only"> to {{ $title }}</span></summary>
                                    <p>{{ $detail }}</p>
                                </details>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="public-section public-care" id="care" aria-labelledby="care-title">
            <div class="public-container public-care__grid">
                <img class="public-care__image" src="{{ asset('images/homepage/placeholders/community.jpg') }}" alt="RLCP community" width="1200" height="680" loading="lazy" decoding="async" data-public-reveal>
                <div data-public-reveal>
                    <p class="public-eyebrow">Walking alongside families</p>
                    <h2 id="care-title">Growing Together<br>Through Care</h2>
                    <p>We work hand in hand with children, families, and our community in Daraga, Albay — sharing God’s love, providing support, and creating opportunities for a brighter future.</p>
                    <a class="public-button public-button--red" href="#contact">Contact RLCP <span class="public-button__arrow"><x-public.icon name="arrow" /></span></a>
                </div>
            </div>
        </section>

        <section class="public-section public-community" id="community" aria-labelledby="community-title">
            <div class="public-container">
                <h2 class="public-lined-heading" id="community-title">Life in <span class="public-warm-text">Our Community</span></h2>
                <div class="public-card-grid">
                    @foreach ([
                        ['learning.png', 'Learning Activities', 'Fun and meaningful learning experiences for children.', 'education-learning', 'Explore Education & Learning'],
                        ['fellowship.jpg', 'Community Fellowship', 'Building friendships and stronger community ties.', 'faith-community', 'Explore Faith & Community'],
                        ['family.jpg', 'Family Engagement', 'Supporting and empowering families for a better tomorrow.', 'care', 'Learn about our care'],
                    ] as [$photo, $title, $description, $target, $label])
                        <figure class="public-gallery-card" data-public-reveal>
                            <img src="{{ asset('images/homepage/placeholders/'.$photo) }}" alt="{{ $title }}" width="960" height="640" loading="lazy" decoding="async">
                            <figcaption>
                                <div><h3>{{ $title }}</h3><p>{{ $description }}</p></div>
                                <a class="public-card-arrow" href="#{{ $target }}" aria-label="{{ $label }}"><x-public.icon name="arrow" /></a>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="public-section public-contact" id="contact" aria-labelledby="contact-title">
            <img class="public-contact__image" src="{{ asset('images/homepage/placeholders/contact.jpg') }}" alt="" width="1200" height="680" loading="lazy" decoding="async">
            <div class="public-container public-contact__content" data-public-reveal>
                <p class="public-eyebrow">Faith. Care. Connection.</p>
                <h2 id="contact-title">Let’s Connect With Our Community</h2>
                <p>Together, we can make a difference in the lives of children and families in Daraga, Albay.</p>
                <div class="public-contact__information">
                    <h3>Getting in touch</h3>
                    <p>Public contact details will be shared here once available. Please check back for ways to get in touch with RLCP.</p>
                </div>
                <a class="public-button public-button--gold" href="#activities">Community Updates <span class="public-button__arrow"><x-public.icon name="arrow" /></span></a>
            </div>
        </section>

        <section class="public-section public-activities" id="activities" aria-labelledby="activities-title">
            <div class="public-container">
                <h2 class="public-lined-heading" id="activities-title">Activities <span class="public-warm-text">& Announcements</span></h2>
                <div class="public-empty-state" data-public-reveal>
                    <span class="public-empty-state__icon"><x-public.icon name="calendar" /></span>
                    <div><h3>Good things are worth sharing.</h3><p>No public announcements just yet. Check back here for approved community news and upcoming activities.</p></div>
                </div>
            </div>
        </section>
    </main>

    <x-public.footer :landing="true" />
    <x-registration.dialog />
    <x-staff-login-dialog />
</body>
</html>
