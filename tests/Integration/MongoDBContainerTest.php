<?php

declare(strict_types=1);

namespace Integration;

use Testcontainers\Container\StartedGenericContainer;
use Testcontainers\Modules\MongoDBContainer;
use Testcontainers\Tests\Integration\ContainerTestCase;

class MongoDBContainerTest extends ContainerTestCase
{
    public function setUp(): void
    {
        if (!extension_loaded('mongodb')) {
            $this->markTestSkipped('MongoDB extension is not installed');
        }

        $this->container = (new MongoDBContainer())->start();
    }

    public function testMongoDBContainer(): void
    {
        self::assertInstanceOf(StartedGenericContainer::class, $this->container);

        $pingResult = $this->container->exec([
            'mongosh', 'admin',
            '-u', 'test',
            '-p', 'test',
            '--eval', "'db.runCommand(\"ping\").ok'",
        ]);

        self::assertEquals(1, $pingResult);
    }
}
