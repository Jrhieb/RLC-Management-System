<?php

namespace Tests\Support;

use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

final class TestIsolation
{
    public static function assertDatabase(string $default, array $connection): void
    {
        if ($default !== 'sqlite'
            || ($connection['driver'] ?? null) !== 'sqlite'
            || ($connection['database'] ?? null) !== ':memory:'
            || ! empty($connection['read']) || ! empty($connection['write'])
            || ! filter_var($connection['foreign_key_constraints'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Tests require SQLite :memory: without connection overrides and with foreign keys enabled.');
        }

        $url = $connection['url'] ?? null;
        if ($url !== null && (! is_string($url) || strpbrk($url, '?#') !== false)) {
            throw new RuntimeException('Tests reject database URL overrides.');
        }
        $resolved = (new ConfigurationUrlParser)->parseConfiguration($connection);
        if ($resolved['driver'] !== 'sqlite' || $resolved['database'] !== ':memory:') {
            throw new RuntimeException('Tests must resolve to SQLite :memory:.');
        }
    }

    public static function assertEnvironment(array $environment): void
    {
        foreach (['APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $name => $expected) {
            if (($environment[$name] ?? null) !== $expected) {
                throw new RuntimeException('Tests reject unsafe environment overrides before application boot.');
            }
        }
        foreach (['APP_BASE_PATH', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE',
            'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE'] as $name) {
            if (($environment[$name] ?? null) !== null) {
                throw new RuntimeException('Tests reject bootstrap path overrides before application boot.');
            }
        }
    }

    public static function assertUncachedBootstrap(array $paths): void
    {
        foreach ($paths as $path) {
            if (file_exists($path)) {
                throw new RuntimeException('Tests refuse existing bootstrap caches before application boot.');
            }
        }
    }

    public static function assertTemporaryPaths(array $paths, string $temporaryDirectory): void
    {
        $temporary = realpath($temporaryDirectory);
        if ($temporary === false) {
            throw new RuntimeException('The test temporary directory must exist.');
        }
        $prefix = self::normalize($temporary).'/';

        foreach ($paths as $path) {
            $resolved = is_string($path) ? realpath($path) : false;
            if ($resolved === false || ! str_starts_with(self::normalize($resolved).'/', $prefix)) {
                throw new RuntimeException('Test storage paths must stay inside the temporary directory.');
            }
        }
    }

    private static function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
