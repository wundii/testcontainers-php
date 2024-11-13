<?php

declare(strict_types=1);

namespace Testcontainers\Container;

use Docker\API\Client;
use Docker\API\Model\ContainersIdExecPostBody;
use Docker\API\Model\IdResponse;
use Docker\API\Runtime\Client\Client as DockerRuntimeClient;
use Docker\Docker;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Testcontainers\ContainerClient\DockerContainerClient;
use Throwable;

class StartedGenericContainer implements StartedTestContainer
{
    protected Docker $dockerClient;

    protected ?string $lastExecId = null;

    public function __construct(protected readonly string $id)
    {
        $this->dockerClient = DockerContainerClient::getDockerClient();
    }


    public function getId(): string
    {
        return $this->id;
    }

    public function getLastExecId(): ?string
    {
        return $this->lastExecId;
    }

    public function getClient(): Docker
    {
        return $this->dockerClient;
    }

    /**
     * @param list<string> $command
     */
    public function exec(array $command): string
    {
        $execConfig = (new ContainersIdExecPostBody())
            ->setCmd($command)
            ->setAttachStdout(true)
            ->setAttachStderr(true);

        // Create and start the exec command
        /** @var IdResponse | null $exec */
        $exec = $this->dockerClient->containerExec($this->id, $execConfig);

        if ($exec === null || $exec->getId() === null) {
            throw new RuntimeException('Failed to create exec command');
        }

        $this->lastExecId = $exec->getId();

        $contents = $this->dockerClient
            ->execStart($this->lastExecId, null, Client::FETCH_RESPONSE)
            ?->getBody()
            ->getContents() ?? '';

        return preg_replace('/[\x00-\x1F\x7F]/u', '', $contents) ?? '';
    }

    public function stop(): StoppedTestContainer
    {
        $this->dockerClient->containerStop($this->id);
        $this->dockerClient->containerDelete($this->id);

        return new StoppedGenericContainer($this->id);
    }

    public function restart(): self
    {
        $this->dockerClient->containerRestart($this->id);

        return $this;
    }

    public function logs(): string
    {
        $output = $this->dockerClient
            ->containerLogs(
                $this->id,
                ['stdout' => true, 'stderr' => true],
                DockerRuntimeClient::FETCH_RESPONSE
            )
            ?->getBody()
            ->getContents() ?? '';

        return preg_replace('/[\x00-\x1F\x7F]/u', '', mb_convert_encoding($output, 'UTF-8', 'UTF-8')) ?? '';
    }

    public function getHost(): string
    {
        return $this->inspect()['NetworkSettings']['Gateway'] ?? '127.0.0.1';
    }

    public function getMappedPort(int $port): int
    {
        $ports = $this->ports();
        if (isset($ports["{$port}/tcp"][0]['HostPort'])) {
            return (int) $ports["{$port}/tcp"][0]['HostPort'];
        }

        throw new RuntimeException("Failed to get mapped port $port for container");
    }

    public function getFirstMappedPort(): int
    {
        $ports = $this->ports();
        $port = array_key_first($ports);

        return (int) $ports[$port][0]['HostPort'];
    }

    public function getName(): string
    {
        return trim($this->inspect()['Name'], '/ ');
    }

    /**
     * @return string[]
     */
    public function getLabels(): array
    {
        return $this->inspect()['Config']['Labels'] ?? [];
    }

    /**
     * @return string[]
     */
    public function getNetworkNames(): array
    {
        $networks = $this->inspect()['NetworkSettings']['Networks'] ?? [];
        return array_keys($networks);
    }

    public function getNetworkId(string $networkName): string
    {
        $networks = $this->inspect()['NetworkSettings']['Networks'];
        if (isset($networks[$networkName])) {
            return $networks[$networkName]['NetworkID'];
        }
        throw new RuntimeException("Network with name {$networkName} not exists");
    }

    public function getIpAddress(string $networkName): string
    {
        $networks = $this->inspect()['NetworkSettings']['Networks'];
        if (isset($networks[$networkName])) {
            return $networks[$networkName]['IPAddress'];
        }
        throw new RuntimeException("Network with name {$networkName} not exists");
    }

    private function inspect(): array
    {
        //For some reason, containerInspect can crash when using FETCH_OBJECT option (e.g. with OpenSearch)
        //should be checked within beluga-php/docker-php client library
        /** @var ResponseInterface | null $containerInspectResponse */
        $containerInspectResponse =  $this->dockerClient->containerInspect($this->id, [], Docker::FETCH_RESPONSE);
        if ($containerInspectResponse === null) {
            throw new RuntimeException('Failed to inspect container');
        }

        try {
            return json_decode(
                $containerInspectResponse->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Failed to inspect container', 0, $exception);
        }
    }

    private function ports(): array
    {
        /** @var array<string, array<array<string, string>>> $ports */
        $ports = $this->inspect()['NetworkSettings']['Ports'] ?? [];

        if ($ports === []) {
            throw new RuntimeException('Failed to get ports from container');
        }

        return $ports;
    }
}
