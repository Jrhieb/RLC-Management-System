<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use RuntimeException;
use Tests\Support\TestIsolation;

abstract class TestCase extends BaseTestCase
{
    private ?string $temporaryStorage = null;

    public function createApplication(): Application
    {
        $environment = [];
        foreach (['APP_ENV', 'MAIL_MAILER', 'SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION',
            'APP_BASE_PATH', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE',
            'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE'] as $name) {
            $environment[$name] = Env::get($name);
        }
        TestIsolation::assertEnvironment($environment);
        TestIsolation::assertDatabase((string) Env::get('DB_CONNECTION'), [
            'driver' => Env::get('DB_CONNECTION'), 'database' => Env::get('DB_DATABASE'),
            'url' => Env::get('DB_URL'), 'foreign_key_constraints' => Env::get('DB_FOREIGN_KEYS', true),
        ]);
        if ($this->temporaryStorage === null) {
            $requestedStorage = Env::get('LARAVEL_STORAGE_PATH');
            if ($requestedStorage !== null) {
                TestIsolation::assertTemporaryPaths([$requestedStorage], sys_get_temp_dir());
            }
            $this->temporaryStorage = sys_get_temp_dir().'/rlcp-tests-'.bin2hex(random_bytes(12));
            foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions',
                'framework/views', 'framework/testing', 'framework/temp', 'logs', 'fonts'] as $directory) {
                (new Filesystem)->makeDirectory($this->temporaryStorage.'/'.$directory, 0755, true);
            }
        }
        $this->setTestEnvironment('LARAVEL_STORAGE_PATH', $this->temporaryStorage);
        $this->setTestEnvironment('VIEW_COMPILED_PATH', $this->temporaryStorage.'/framework/views');
        $this->setTestEnvironment('APP_KEY', 'base64:'.base64_encode(str_repeat('r', 32)));
        $this->setTestEnvironment('APP_PREVIOUS_KEYS', '');

        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->useEnvironmentPath($this->temporaryStorage);
        // Each application boot selects a fresh temporary bootstrap directory.
        // Refuse cached inputs; never clear or load the LAN bootstrap caches.
        $app->useBootstrapPath($this->temporaryStorage.'/bootstrap-'.bin2hex(random_bytes(8)));
        (new Filesystem)->makeDirectory($app->bootstrapPath('cache'), 0755, true);
        TestIsolation::assertTemporaryPaths([$app->environmentPath(), $app->bootstrapPath()], $this->temporaryStorage);
        TestIsolation::assertUncachedBootstrap([
            $app->getCachedConfigPath(), $app->getCachedRoutesPath(), $app->getCachedEventsPath(),
            $app->getCachedServicesPath(), $app->getCachedPackagesPath(),
        ]);
        $app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            TestIsolation::assertDatabase($app['config']->get('database.default'), $app['config']->get('database.connections.sqlite'));
            if ($app['config']->get('mail.default') !== 'array'
                || $app['config']->get('session.driver') !== 'array'
                || $app['config']->get('cache.default') !== 'array'
                || $app['config']->get('queue.default') !== 'sync') {
                throw new RuntimeException('Tests reject unsafe configuration before providers boot.');
            }
        });
        $app->booting(function (): void {
            Mail::fake();
            Notification::fake();
        });
        $app->make(Kernel::class)->bootstrap();
        $config = $app->make('config');

        // Run before RefreshDatabase or any migration hook can touch a connection.
        TestIsolation::assertDatabase($config->get('database.default'), $config->get('database.connections.sqlite'));
        if (! $app->environment('testing')
            || $config->get('mail.default') !== 'array'
            || $config->get('session.driver') !== 'array'
            || $config->get('cache.default') !== 'array'
            || $config->get('queue.default') !== 'sync'
            || ! in_array($config->get('filesystems.default'), ['local', 'public'], true)) {
            throw new RuntimeException('Tests require guarded SQLite :memory: and isolated mail, sessions, cache, and queues.');
        }

        TestIsolation::assertTemporaryPaths([
            $app->storagePath(), $config->get('filesystems.disks.local.root'),
            $config->get('filesystems.disks.public.root'), $config->get('view.compiled'),
            $config->get('session.files'), $config->get('cache.stores.file.path'),
            $config->get('dompdf.options.font_dir'), $config->get('dompdf.options.font_cache'),
            dirname($config->get('logging.channels.single.path')),
        ], $this->temporaryStorage);
        $config->set('dompdf.options.temp_dir', $this->temporaryStorage.'/framework/temp');

        Mail::fake();
        Notification::fake();

        return $app;
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->temporaryStorage !== null) {
                TestIsolation::assertTemporaryPaths([$this->temporaryStorage], sys_get_temp_dir());
                (new Filesystem)->deleteDirectory($this->temporaryStorage);
                $this->temporaryStorage = null;
                foreach (['LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'APP_KEY', 'APP_PREVIOUS_KEYS'] as $name) {
                    unset($_ENV[$name], $_SERVER[$name]);
                    putenv($name);
                }
            }
        }
    }

    private function setTestEnvironment(string $name, string $value): void
    {
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
