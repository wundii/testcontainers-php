<?php

declare(strict_types=1);

namespace Testcontainers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Testcontainers\Container\StartedTestContainer;

abstract class ContainerTestCase extends TestCase
{
    protected StartedTestContainer $container;

    protected function tearDown(): void
    {
        $this->container->stop();
        parent::tearDown();
    }
}
