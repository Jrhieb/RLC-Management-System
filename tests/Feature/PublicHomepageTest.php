<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TestIsolation;
use Tests\TestCase;

class PublicHomepageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No RefreshDatabase: these public/guest checks must not need a schema,
        // migrations, or even a single database query.
        DB::connection()->beforeExecuting(function (): void {
            throw new RuntimeException('Public homepage and guest checks must not query records.');
        });
        Vite::useHotFile($this->app->storagePath('framework/testing/unused-homepage.hot'));
        URL::forceRootUrl('http://rlcp-homepage.test');
    }

    public function test_homepage_renders_public_content_without_querying_operational_records(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()->assertViewIs('welcome')->assertSeeInOrder([
            'Start an Application',
            'Growing in Faith.', 'Building Brighter Futures.',
            'A Community That Cares,', 'A Future Filled With Hope',
            'Our Story', 'Our Mission', 'Our Vision',
            'Our Programs', 'Growing Together', 'Life in',
            'Let’s Connect With Our Community', 'Activities',
            'No public announcements just yet.', 'For Staff',
        ])->assertDontSee('data-flux-sidebar', false)
            ->assertDontSee('wire:snapshot', false);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();

        // Optional fixtures for offline browser checks contain public markup only.
        $directory = getenv('HOMEPAGE_UI_FIXTURES');
        if (is_string($directory) && $directory !== '') {
            TestIsolation::assertTemporaryPaths([$directory], sys_get_temp_dir());
            file_put_contents($directory.'/homepage.html', $response->getContent());
        }
    }

    public function test_every_link_and_image_has_a_real_public_destination(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $ids = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->hasAttribute('id')) {
                $id = $element->getAttribute('id');
                $this->assertNotContains($id, $ids, 'IDs must be unique.');
                $ids[] = $id;
            }
        }
        foreach ($document->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            if (str_starts_with($href, '#')) {
                $this->assertNotSame('#', $href);
                $this->assertContains(substr($href, 1), $ids, 'An anchor must target a real homepage section.');
            } elseif ($href === route('home').'#staff-login') {
                $this->assertTrue($link->hasAttribute('data-staff-login-open'));
                $this->assertContains('staff-login-dialog', $ids);
            } else {
                $this->assertContains($href, [route('login'), route('register'), route('password.request'), route('program-application'), route('about'), route('programs'), route('public.activities.index')]);
            }
        }
        foreach ($document->getElementsByTagName('img') as $image) {
            $path = parse_url($image->getAttribute('src'), PHP_URL_PATH);
            $this->assertTrue(str_starts_with($path, '/images/homepage/') || $path === '/images/branding/rlc-logo.png');
            $this->assertFileExists(public_path($path));
            $this->assertTrue($image->hasAttribute('alt'));
            if (! $image->hasAttribute('class') || $image->getAttribute('class') !== 'staff-login-logo') {
                $this->assertGreaterThan(0, (int) $image->getAttribute('width'));
                $this->assertGreaterThan(0, (int) $image->getAttribute('height'));
            }
        }
        $this->assertCount(1, $document->getElementsByTagName('h1'));
        $this->assertCount(3, $document->getElementsByTagName('form'));
        $this->assertSame(route('registration.request'), $document->getElementsByTagName('form')->item(0)->getAttribute('action'));
        $this->assertSame(route('registration.code.verify'), $document->getElementsByTagName('form')->item(1)->getAttribute('action'));
        $this->assertSame(route('login.store'), $document->getElementsByTagName('form')->item(2)->getAttribute('action'));
    }

    public function test_public_application_is_an_explicit_draft_without_an_intake_endpoint(): void
    {
        Queue::fake();
        $response = $this->get(route('program-application'))->assertOk()->assertViewIs('public.application')
            ->assertSee('Draft preview')->assertSee('Applications are not open')
            ->assertSee('Nothing is sent, saved, or emailed.')
            ->assertSee('form-action', false)->assertSee('method="dialog"', false)
            ->assertSee('<fieldset disabled>', false)
            ->assertDontSee('name="guardian', false)->assertDontSee('name="contact', false)
            ->assertDontSee('data-flux-sidebar', false);
        $directory = getenv('HOMEPAGE_UI_FIXTURES');
        if (is_string($directory) && $directory !== '') {
            TestIsolation::assertTemporaryPaths([$directory], sys_get_temp_dir());
            file_put_contents($directory.'/application.html', $response->getContent());
        }
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_about_is_a_public_placeholder_page_without_record_access(): void
    {
        Queue::fake();
        $response = $this->get('/about')->assertOk()->assertViewIs('public.about')
            ->assertSeeTextInOrder(['About RLCP', 'Our Story', 'Mission', 'Vision', 'Values',
                'Community photo', 'Optional video', 'Key Milestones', 'Our People', 'For Staff'])
            ->assertSee('Official history pending RLCP approval.')
            ->assertSee('Year and milestone pending approval.')
            ->assertSee('Name and role pending approval.')
            ->assertDontSee('<iframe', false)->assertDontSee('<video', false)
            ->assertSee('id="staff-login-dialog"', false)->assertDontSee('mailto:', false)->assertDontSee('tel:', false)
            ->assertDontSee('data-flux-sidebar', false);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertCount(1, $document->getElementsByTagName('h1'));
        $this->assertCount(2, (new DOMXPath($document))->query('//img[not(ancestor::dialog)]'));
        $this->assertCount(0, (new DOMXPath($document))->query('//main//form'));
        foreach ((new DOMXPath($document))->query('//img[not(ancestor::dialog)]') as $image) {
            $this->assertSame('/images/branding/rlc-logo.png', parse_url($image->getAttribute('src'), PHP_URL_PATH));
            $this->assertTrue($image->hasAttribute('alt'));
            $this->assertNotEmpty($image->parentNode->getAttribute('aria-label'));
        }
        $directory = getenv('HOMEPAGE_UI_FIXTURES');
        if (is_string($directory) && $directory !== '') {
            TestIsolation::assertTemporaryPaths([$directory], sys_get_temp_dir());
            file_put_contents($directory.'/about.html', $response->getContent());
        }
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_public_page_links_open_the_page_and_identify_the_current_destination(): void
    {
        foreach (['home', 'program-application', 'about', 'programs', 'public.activities.index'] as $page) {
            $response = $this->get(route($page))->assertOk();
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $navigation = (new DOMXPath($document))->query('//nav[@aria-label="Main navigation"]/a');
            $current = [];
            foreach ($navigation as $link) {
                if ($link->hasAttribute('aria-current')) {
                    $current[] = trim($link->textContent);
                }
            }
            $this->assertSame([match ($page) {
                'home' => 'Home', 'about' => 'About', 'programs' => 'Programs', 'public.activities.index' => 'Activities', default => 'Start an Application',
            }], $current);
            foreach ($document->getElementsByTagName('a') as $link) {
                if (in_array(trim($link->textContent), ['About', 'About RLCP'], true)) {
                    $this->assertSame(route('about'), $link->getAttribute('href'));
                }
                if (trim($link->textContent) === 'Programs') {
                    $this->assertSame(route('programs'), $link->getAttribute('href'));
                }
                if (trim($link->textContent) === 'Activities') {
                    $this->assertSame(route('public.activities.index'), $link->getAttribute('href'));
                }
            }
        }
    }

    public function test_programs_renders_draft_content_and_native_article_previews_without_records(): void
    {
        Queue::fake();
        $response = $this->get('/programs')->assertOk()->assertViewIs('public.programs')
            ->assertSeeTextInOrder(['Growing Together,', 'Guided by Care', 'Explore Programs',
                'How We Support Growth', 'Learning & Development', 'Family & Community Care',
                'Faith & Formation', 'Stories & Insights', 'Start an Application'])
            ->assertSee('Content pending RLCP approval.')
            ->assertSee('These are proposed categories for this preview, not confirmed RLCP programs.')
            ->assertSee('Online applications are not open yet.')
            ->assertSee('id="staff-login-dialog"', false)->assertDontSee('mailto:', false)->assertDontSee('tel:', false)
            ->assertDontSee('data-flux-sidebar', false);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertCount(1, $document->getElementsByTagName('h1'));
        $this->assertCount(3, $document->getElementsByTagName('details'));
        $this->assertCount(0, (new DOMXPath($document))->query('//main//form'));
        foreach ($document->getElementsByTagName('details') as $preview) {
            $this->assertStringContainsString('Read Article', $preview->textContent);
            $this->assertStringContainsString('Draft preview', $preview->textContent);
        }
        foreach ((new DOMXPath($document))->query('//img[not(ancestor::dialog)]') as $image) {
            $this->assertSame('/images/branding/rlc-logo.png', parse_url($image->getAttribute('src'), PHP_URL_PATH));
        }
        $directory = getenv('HOMEPAGE_UI_FIXTURES');
        if (is_string($directory) && $directory !== '') {
            TestIsolation::assertTemporaryPaths([$directory], sys_get_temp_dir());
            file_put_contents($directory.'/programs.html', $response->getContent());
        }
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_public_application_rejects_submissions_without_dispatching_anything(): void
    {
        Queue::fake();
        $this->post(route('program-application'), ['guardian' => 'Synthetic Guardian'])->assertStatus(405);
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    #[DataProvider('publicActivityFilters')]
    public function test_public_activities_filters_render_safe_empty_states_without_querying_records(string $query, string $filter, string $message): void
    {
        Queue::fake();
        $response = $this->get('/community/activities'.$query)->assertOk()->assertViewIs('public.activities')
            ->assertSee('Community in Action')
            ->assertSee('No public activities to share yet.')
            ->assertSee($message)
            ->assertDontSee('Attendance records')->assertDontSee('View Details')
            ->assertDontSee('data-flux-sidebar', false)->assertDontSee('data-profile-name', false)
            ->assertSee('id="staff-login-dialog"', false)->assertDontSee('mailto:', false)->assertDontSee('tel:', false);

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $this->assertCount(0, $xpath->query('//main//form'));
        $filters = $xpath->query('//nav[@aria-label="Activity filters"]/a');
        $this->assertCount(3, $filters);
        $current = [];
        foreach ($filters as $link) {
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $parameters);
            $this->assertContains($parameters['filter'], ['upcoming', 'past', 'all']);
            $this->assertSame(route('public.activities.index', ['filter' => $parameters['filter']]).'#activity-list', $link->getAttribute('href'));
            if ($link->getAttribute('aria-current') === 'page') {
                $current[] = $parameters['filter'];
            }
        }
        $this->assertSame([$filter], $current);
        $this->assertCount(0, $xpath->query('//nav[@aria-label="Pagination"] | //*[@aria-roledescription="carousel"]'));
        foreach ($xpath->query('//img[not(ancestor::dialog)]') as $image) {
            $this->assertSame('/images/branding/rlc-logo.png', parse_url($image->getAttribute('src'), PHP_URL_PATH));
        }
        foreach ($document->getElementsByTagName('a') as $link) {
            $this->assertNotSame('Contact', trim($link->textContent));
            $this->assertFalse(str_starts_with((string) parse_url($link->getAttribute('href'), PHP_URL_PATH), '/activities'));
        }

        $directory = getenv('HOMEPAGE_UI_FIXTURES');
        if (is_string($directory) && $directory !== '' && $query === '?filter='.$filter) {
            TestIsolation::assertTemporaryPaths([$directory], sys_get_temp_dir());
            file_put_contents($directory.'/activities-'.$filter.'.html', $response->getContent());
        }
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public static function publicActivityFilters(): array
    {
        return [
            'default' => ['', 'upcoming', 'No upcoming public activities.'],
            'upcoming' => ['?filter=upcoming', 'upcoming', 'No upcoming public activities.'],
            'past' => ['?filter=past', 'past', 'No past public activities.'],
            'all' => ['?filter=all', 'all', 'No public activities yet.'],
            'unknown filter' => ['?filter=unapproved', 'upcoming', 'No upcoming public activities.'],
            'array filter' => ['?filter%5B%5D=all', 'upcoming', 'No upcoming public activities.'],
        ];
    }

    public function test_public_activities_cannot_select_or_open_an_operational_activity(): void
    {
        $this->get('/community/activities?filter=all&activity_id=1&is_public=1&page=2')->assertOk()
            ->assertSee('No public activities yet.')
            ->assertDontSee('View Details')->assertDontSee('Attendance records');
        $this->get('/community/activities/1')->assertNotFound();
        $this->get('/activities/1')->assertRedirect(route('login'));
        $this->getJson('/activities/1')->assertUnauthorized();
    }

    public function test_homepage_assets_are_separate_from_existing_authentication_assets(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $publicCss = $manifest['resources/css/public-home.css']['file'];
        $publicJs = $manifest['resources/js/public-home.js']['file'];
        $staffCss = $manifest['resources/css/app.css']['file'];
        $staffJs = $manifest['resources/js/app.js']['file'];

        $this->get(route('home'))->assertOk()
            ->assertSee($publicCss, false)->assertSee($publicJs, false)
            ->assertDontSee($staffCss, false)->assertDontSee($staffJs, false);

        $this->get(route('login'))->assertOk()
            ->assertSee($staffCss, false)->assertSee($staffJs, false)
            ->assertDontSee($publicCss, false)->assertDontSee($publicJs, false)
            ->assertDontSee('data-public-home', false);
        $this->get(route('register'))->assertOk()
            ->assertSee($manifest['resources/css/registration.css']['file'], false)
            ->assertSee($manifest['resources/js/registration-ui.js']['file'], false)
            ->assertDontSee($staffCss, false)->assertDontSee($staffJs, false)
            ->assertDontSee($publicCss, false)->assertDontSee($publicJs, false);
    }

    #[DataProvider('staffUrls')]
    public function test_existing_staff_endpoints_still_require_login(string $url): void
    {
        $this->get($url)->assertRedirect(route('login'));
        $this->getJson($url)->assertUnauthorized();
    }

    public static function staffUrls(): array
    {
        return [
            ['/dashboard'], ['/activities'], ['/activities/create'], ['/activities/1'],
            ['/attendance'], ['/reports'], ['/beneficiaries'], ['/beneficiaries/1/photo'],
            ['/dashboard/notifications'], ['/account/notifications'],
        ];
    }
}
