<?php

declare(strict_types=1);

namespace Testcontainers\Tests\Integration;

use Docker\API\Model\ContainersIdJsonGetResponse200;
use PHPUnit\Framework\TestCase;
use Testcontainers\Container\GenericContainer;
use Testcontainers\Utils\PortGenerator\FixedPortGenerator;
use Testcontainers\Wait\WaitForHostPort;

class GenericContainerTest extends TestCase
{
    public function testExec(): void
    {
        $container = (new GenericContainer('alpine'))
            ->withCommand(['tail', '-f', '/dev/null'])
            ->start();
        $result = $container->exec(['echo', 'testcontainers']);

        self::assertSame('testcontainers', $result);

        $container->stop();
    }

    /**
     * @throws \JsonException
     */
    public function testShouldReturnFirstMappedPort(): void
    {
        $container = (new GenericContainer('nginx'))
            ->withPortGenerator(new FixedPortGenerator([8080]))
            ->withExposedPorts(80)
            ->withWait(new WaitForHostPort(8080))
            ->start();
        $firstMappedPort = $container->getFirstMappedPort();

        self::assertSame($firstMappedPort, 8080, 'First mapped port does not match 8080');

        $container->stop();
    }

    public function testShouldCaptureStderrWhenCommandFails(): void
    {
        $container = (new GenericContainer('alpine'))
            ->withCommand(['tail', '-f', '/dev/null'])
            ->start();
        $result = $container->exec(['ls', '/nonexistent/path']);

        self::assertStringContainsString('No such file or directory', $result, 'Expected stderr in the output');

        $container->stop();
    }

    public function testShouldSetEnvironmentVariables(): void
    {
        $container = (new GenericContainer('alpine'))
            ->withCommand(['tail', '-f', '/dev/null'])
            ->withEnvironment(['TEST_ENV' => 'testValue'])
            ->start();
        $output = $container->exec(['env']);

        self::assertStringContainsString('TEST_ENV=testValue', $output);

        $container->stop();
    }

    public function testShouldSetEntrypoint(): void
    {
        $container = (new GenericContainer('cristianrgreco/testcontainer:1.1.14'))
            ->withEntrypoint('node')
            ->withCommand(['index.js'])
            ->withExposedPorts(8080)
            ->start();

        /** @var ContainersIdJsonGetResponse200|null $inspectResult */
        $inspectResult = $container->getClient()->containerInspect($container->getId());
        $entrypoint = $inspectResult?->getConfig()?->getEntrypoint() ?? [];

        self::assertContains('node', $entrypoint);

        $container->stop();
    }

    public function testShouldSetMount(): void
    {
        $localPath = __DIR__ . '/../Fixtures/Docker';
        $containerPath = '/mnt/test-data';

        $container = (new GenericContainer('alpine'))
            ->withMount($localPath, $containerPath)
            ->withCommand(['tail', '-f', '/dev/null'])
            ->start();

        $result = $container->exec(["cat", $containerPath.'/test.txt']);
        self::assertSame('hello world', $result);
    }

    public function testShouldSetPrivilegedMode(): void
    {
        $container = (new GenericContainer('alpine'))
            ->withPrivilegedMode()
            ->withCommand(['tail', '-f', '/dev/null'])
            ->start();

        /** @var ContainersIdJsonGetResponse200|null $inspectResult */
        $inspectResult = $container->getClient()->containerInspect($container->getId());
        $privileged = $inspectResult?->getHostConfig()?->getPrivileged();

        self::assertTrue($privileged);
    }
}
