<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DatabaseSchemaConfigurationTest extends TestCase
{
    #[DataProvider('schemaEnvironments')]
    public function test_postgresql_schema_can_be_selected_without_changing_the_local_default(?string $schema, string $expected): void
    {
        $container = Container::getInstance();
        $environment = Env::getRepository();
        $originalSchema = $environment->get('DB_SEARCH_PATH');
        $environment->clear('DB_SEARCH_PATH');

        if ($schema !== null) {
            $environment->set('DB_SEARCH_PATH', $schema);
        }

        try {
            new Application(dirname(__DIR__, 2));

            $configuration = require dirname(__DIR__, 2).'/config/database.php';

            $this->assertSame($expected, $configuration['connections']['pgsql']['search_path']);
            $this->assertSame('sqlite', $configuration['default']);
        } finally {
            $environment->clear('DB_SEARCH_PATH');

            if ($originalSchema !== null) {
                $environment->set('DB_SEARCH_PATH', $originalSchema);
            }

            Container::setInstance($container);
        }
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function schemaEnvironments(): array
    {
        return [
            'unchanged default' => [null, 'public'],
            'private staging schema' => ['laravel', 'laravel'],
        ];
    }
}
