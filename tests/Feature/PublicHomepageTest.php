<?php

namespace Tests\Feature;

use DOMDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
            'Welcome to Risen Lord Church Phils., Inc.',
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
            } else {
                $this->assertContains($href, [route('login'), route('register')]);
            }
        }
        foreach ($document->getElementsByTagName('img') as $image) {
            $path = parse_url($image->getAttribute('src'), PHP_URL_PATH);
            $this->assertTrue(str_starts_with($path, '/images/homepage/') || $path === '/images/branding/rlc-logo.png');
            $this->assertFileExists(public_path($path));
            $this->assertTrue($image->hasAttribute('alt'));
            $this->assertGreaterThan(0, (int) $image->getAttribute('width'));
            $this->assertGreaterThan(0, (int) $image->getAttribute('height'));
        }
        $this->assertCount(1, $document->getElementsByTagName('h1'));
        $this->assertCount(1, $document->getElementsByTagName('form'));
        $this->assertSame(route('registration.request'), $document->getElementsByTagName('form')->item(0)->getAttribute('action'));
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
