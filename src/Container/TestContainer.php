<?php

declare(strict_types=1);

namespace Testcontainers\Container;

use Testcontainers\Utils\PortGenerator\PortGenerator;
use Testcontainers\Wait\WaitStrategy;

interface TestContainer
{
    public function start(): StartedGenericContainer;

    /**
     * @param array<string> $command
     */
    public function withCommand(array $command): static;

    public function withEntrypoint(string $entryPoint): static;

    /**
     * TODO: replace with array after deprecated implementation is removed
     * @param array<string, string>|string $env
     */
    public function withEnvironment(array | string $env, ?string $value): static;

    /**  @param int|string|array<int|string> $ports One or more ports to expose. */
    public function withExposedPorts(...$ports): static;

    public function withHealthCheckCommand(
        string $command,
        int $intervalInMilliseconds,
        int $timeoutInMilliseconds,
        int $retries,
        int $startPeriodInMilliseconds
    ): static;

    public function withHostname(string $hostname): static;

    /**
     * @param array<string, string> $labels
     */
    public function withLabels(array $labels): static;

    public function withMount(string $localPath, string $containerPath): static;

    public function withName(string $name): static;

    public function withNetwork(string $networkName): static;

    public function withPortGenerator(PortGenerator $portGenerator): static;

    public function withPrivilegedMode(bool $privileged): static;

    public function withWait(WaitStrategy $waitStrategy): static;
}
