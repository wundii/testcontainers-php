<?php

declare(strict_types=1);

namespace Testcontainers\Wait;

use Testcontainers\Container\StartedTestContainer;
use Testcontainers\Exception\ContainerWaitingTimeoutException;

class WaitForHostPort extends BaseWaitStrategy
{
    public function __construct(
        protected int $port,
        int $timeout = 10000,
        int $pollInterval = 500
    ) {
        parent::__construct($timeout, $pollInterval);
    }

    public function wait(StartedTestContainer $container): void
    {
        $startTime = microtime(true) * 1000;
        $containerAddress = $container->getHost();

        while (true) {
            $elapsedTime = (microtime(true) * 1000) - $startTime;

            if ($elapsedTime > $this->timeout) {
                throw new ContainerWaitingTimeoutException($container->getId());
            }

            if ($this->isPortOpen($containerAddress, $this->port)) {
                return; // Port is open, container is ready
            }

            usleep($this->pollInterval * 1000); // Wait for the next polling interval
        }
    }

    private function isPortOpen(string $ipAddress, int $port): bool
    {
        $connection = @fsockopen($ipAddress, $port, $errno, $errstr, 2);

        if ($connection !== false) {
            fclose($connection);
            return true;
        }

        return false;
    }
}
