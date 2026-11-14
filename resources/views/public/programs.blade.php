<!DOCTYPE html>
<html lang="en" class="rlcp-public-document">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Explore the RLCP Programs page preview. Program information and draft articles are awaiting RLCP approval.">
    <meta name="theme-color" content="#A50021">
    <meta name="robots" content="noindex, nofollow">
    <title>Programs | RLCP — Preview</title>
    <link rel="icon" href="{{ asset('images/branding/rlc-logo.png') }}" type="image/png">
    @fonts
    @vite(['resources/css/public-home.css', 'resources/js/public-home.js'])
</head>
<body class="rlcp-public public-programs-page" id="page-top" data-public-home>
    <a class="public-skip" href="#main-content">Skip to content</a>
    <div class="programs-header"><x-public.header /></div>
    <main id="main-content" tabindex="-1">
        <section class="programs-hero public-container" aria-labelledby="programs-title">
            <div class="programs-hero__layout">
                <div class="programs-hero__copy">
                    <p class="public-eyebrow">Our Programs · Preview</p>
                    <h1 id="programs-title">Growing Together,<br>Guided by Care</h1>
                    <p>Draft introduction: exploring learning, family connection, community, and faith-centered care. Content pending RLCP approval.</p>
                    <a class="public-button public-button--gold programs-button" href="#program-overview">Explore Programs <x-public.icon name="arrow" /></a>
                </div>
                <x-public.media-placeholder class="programs-hero__photo" label="Community hero photo placeholder" />
            </div>
        </section>

        <section class="public-section programs-overview" id="program-overview" tabindex="-1" aria-labelledby="program-overview-title">
            <div class="public-container">
                <h2 class="programs-section-title" id="program-overview-title">How We Support <span>Growth</span></h2>
                <p class="programs-section-intro">These are proposed categories for this preview, not confirmed RLCP programs.</p>
                <div class="programs-overview__grid">
                    <x-public.media-placeholder class="programs-overview__photo" label="Family and community photo placeholder" />
                    <div class="programs-categories">
                        {{-- Editable draft categories. Replace only with RLCP-approved names and descriptions. --}}
                        @foreach (['Learning & Development' => 'faith', 'Family & Community Care' => 'community', 'Faith & Formation' => 'church'] as $title => $icon)
                            <article class="programs-category" data-public-reveal>
                                <span class="programs-category__icon"><x-public.icon :name="$icon" /></span>
                                <div><p class="programs-category__label">Proposed category</p><h3>{{ $title }}</h3><p>Content pending RLCP approval.</p></div>
                            </article>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <section class="public-section programs-stories" aria-labelledby="program-stories-title">
            <div class="public-container">
                <h2 class="programs-section-title" id="program-stories-title">Stories & <span>Insights</span></h2>
                <p class="programs-section-intro">Sample reflections for layout review. These drafts are not published RLCP articles or personal testimonies.</p>
                <div class="programs-stories__grid">
                    @foreach ([
                        [
                            'title' => 'Growing Together, One Conversation at a Time',
                            'image' => 'Family connection article photo placeholder',
                            'excerpt' => 'Draft reflection on listening, shared activities, and making room for family connection.',
                            'paragraphs' => [
                                'A shared story, a simple drawing, or a question about the day can become an invitation to spend time together. This draft explores small moments of curiosity and patient attention.',
                                'Families have different routines and circumstances. A future article could offer approved ideas for learning together, with room for families to choose what fits their lives.',
                            ],
                        ],
                        [
                            'title' => 'A Place to Learn and Belong',
                            'image' => 'Learning and community article photo placeholder',
                            'excerpt' => 'Draft reflection on welcoming spaces where children and families can ask questions and share ideas.',
                            'paragraphs' => [
                                'Welcoming spaces make room for questions, different experiences, and time to listen. This sample considers how an invitation to read, create, or explore might begin with the interests of a child and their family.',
                                'Specific learning activities and program information would need RLCP review before publication. This reflection does not describe an existing service or promise an outcome.',
                            ],
                        ],
                        [
                            'title' => 'Serving Together with Purpose',
                            'image' => 'Faith and care article photo placeholder',
                            'excerpt' => 'Draft reflection on faith, kindness, and everyday choices that make room for care in a community.',
                            'paragraphs' => [
                                'Offering time, listening with patience, or sharing a task are ordinary ways to express care. This sample explores those themes without describing a specific RLCP activity or participant.',
                                'A future reflection could consider how faith shapes an attentive and respectful approach to serving others. Its message and any examples would need RLCP approval.',
                            ],
                        ],
                    ] as $article)
                        <article class="programs-article {{ $loop->first ? 'programs-article--featured' : '' }}" data-public-reveal>
                            <x-public.media-placeholder class="programs-article__photo" :label="$article['image']" />
                            <div class="programs-article__content">
                                <p class="programs-article__status"><strong>Draft article</strong><span>Pending RLCP approval</span></p>
                                <h3>{{ $article['title'] }}</h3>
                                <p class="programs-article__excerpt">{{ $article['excerpt'] }}</p>
                                <details class="programs-article-preview">
                                    <summary><span class="programs-read-more">Read Article</span><span class="programs-read-less">Close Preview</span><span class="public-sr-only">: {{ $article['title'] }}</span><x-public.icon name="arrow" /></summary>
                                    <div class="programs-article-preview__body">
                                        <p class="programs-article-preview__notice">Draft preview · Content pending RLCP approval.</p>
                                        @foreach ($article['paragraphs'] as $paragraph)
                                            <p>{{ $paragraph }}</p>
                                        @endforeach
                                    </div>
                                </details>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="public-section programs-application" aria-labelledby="program-application-title">
            <div class="public-container programs-application__grid">
                <x-public.media-placeholder class="programs-application__photo" label="Community care image placeholder" />
                <div class="programs-application__copy">
                    <h2 id="program-application-title">Start an <span>Application</span></h2>
                    <p>The application page is a draft preview. Online applications are not open yet. Eligibility and intake details are awaiting RLCP approval.</p>
                    <a class="public-button public-button--gold programs-button" href="{{ route('program-application') }}">Start an Application <x-public.icon name="arrow" /></a>
                </div>
            </div>
        </section>
    </main>
    <x-public.footer />
    <x-registration.dialog />
    <x-staff-login-dialog />
</body>
</html>
