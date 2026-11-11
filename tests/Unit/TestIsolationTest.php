<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestIsolation;

class TestIsolationTest extends TestCase
{
    #[DataProvider('memoryConnections')]
    public function test_memory_configuration_with_or_without_a_url_is_allowed(?string $url): void
    {
        TestIsolation::assertDatabase('sqlite', ['url' => $url] + $this->connection());
        $this->addToAssertionCount(1);
    }

    public static function memoryConnections(): array
    {
        return [[null], [''], ['sqlite:///:memory:']];
    }

    #[DataProvider('unsafeConnections')]
    public function test_database_guard_rejects_unsafe_configuration_before_connecting(string $default, array $overrides): void
    {
        $this->expectException(RuntimeException::class);
        TestIsolation::assertDatabase($default, $overrides + $this->connection());
    }

    public static function unsafeConnections(): array
    {
        return [
            'disk database URL' => ['sqlite', ['url' => 'sqlite:///private.sqlite']],
            'URL query override' => ['sqlite', ['url' => 'sqlite:///:memory:?database=private.sqlite']],
            'URL foreign key override' => ['sqlite', ['url' => 'sqlite:///:memory:?foreign_key_constraints=false']],
            'different driver' => ['sqlite', ['driver' => 'mysql']],
            'disk database option' => ['sqlite', ['database' => 'private.sqlite']],
            'different default' => ['mysql', []],
            'read override' => ['sqlite', ['read' => ['database' => 'private.sqlite']]],
            'write override' => ['sqlite', ['write' => ['database' => 'private.sqlite']]],
            'foreign keys disabled' => ['sqlite', ['foreign_key_constraints' => false]],
        ];
    }

    #[DataProvider('unsafeEnvironments')]
    public function test_environment_guard_rejects_overrides_before_application_boot(string $name, mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        TestIsolation::assertEnvironment([$name => $value] + [
            'APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
    }

    public static function unsafeEnvironments(): array
    {
        return [
            ['APP_ENV', 'local'], ['MAIL_MAILER', 'smtp'], ['SESSION_DRIVER', 'database'],
            ['CACHE_STORE', 'database'], ['QUEUE_CONNECTION', 'database'], ['APP_BASE_PATH', 'elsewhere'],
            ['APP_CONFIG_CACHE', 'bootstrap/cache/config.php'], ['APP_ROUTES_CACHE', 'routes.php'],
            ['APP_EVENTS_CACHE', 'events.php'], ['APP_SERVICES_CACHE', 'services.php'], ['APP_PACKAGES_CACHE', 'packages.php'],
        ];
    }

    #[DataProvider('bootstrapCaches')]
    public function test_existing_selected_bootstrap_caches_fail_without_being_loaded(string $filename): void
    {
        $directory = sys_get_temp_dir().'/rlcp-cache-guard-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($directory);
        $path = $directory.'/'.$filename;
        file_put_contents($path, '<?php throw new \\LogicException("A cache must never be loaded.");');
        try {
            TestIsolation::assertUncachedBootstrap([$path]);
            $this->fail('An existing bootstrap cache passed the guard.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Tests refuse existing bootstrap caches before application boot.', $exception->getMessage());
        } finally {
            TestIsolation::assertTemporaryPaths([$directory], sys_get_temp_dir());
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public static function bootstrapCaches(): array
    {
        return [['config.php'], ['routes-v7.php'], ['events.php'], ['services.php'], ['packages.php']];
    }

    public function test_storage_guard_rejects_escapes_and_similarly_named_siblings(): void
    {
        $root = sys_get_temp_dir().'/rlcp-isolation-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        $files->makeDirectory($root.'/inside', 0755, true);
        $files->makeDirectory($root.'-sibling', 0755, true);
        try {
            TestIsolation::assertTemporaryPaths([$root.'/inside'], $root);
            $this->addToAssertionCount(1);
            foreach ([$root.'/..', $root.'-sibling', dirname(__DIR__, 2), $root.'/missing'] as $outside) {
                try {
                    TestIsolation::assertTemporaryPaths([$outside], $root);
                    $this->fail('An unsafe storage path passed the guard.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Test storage paths must stay inside the temporary directory.', $exception->getMessage());
                }
            }
        } finally {
            TestIsolation::assertTemporaryPaths([$root, $root.'-sibling'], sys_get_temp_dir());
            $files->deleteDirectory($root);
            $files->deleteDirectory($root.'-sibling');
        }
    }

    private function connection(): array
    {
        return ['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'sqlite:///:memory:'];
    }
}
