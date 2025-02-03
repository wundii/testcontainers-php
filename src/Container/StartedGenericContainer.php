<?php

declare(strict_types=1);

namespace Testcontainers\Container;

use Docker\API\Client;
use Docker\API\Model\ContainersIdExecPostBody;
use Docker\API\Model\ContainersIdJsonGetResponse200;
use Docker\API\Model\IdResponse;
use Docker\API\Runtime\Client\Client as DockerRuntimeClient;
use Docker\Docker;
use JsonException;
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
        return '127.0.0.1';
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
        /** @var array{NetworkSettings?: array{Networks?: array<string, mixed>}} $inspectData */
        $inspectData = $this->inspect();
        $networks = $inspectData['NetworkSettings']['Networks'] ?? [];
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

    /**
     * @return array<string, mixed> The container details.
     * @throws RuntimeException If the container inspection fails or the response format is invalid.
     * TODO: refactor with object after beluga-php/docker-php client library is fixed
     */
    protected function inspect(): array
    {
        try {
            /**
             * For some reason, containerInspect can crash when using FETCH_OBJECT option (e.g. with OpenSearch)
             * This is a workaround until the issue is fixed (should be checked within beluga-php/docker-php client library)
             */
            /** @var ResponseInterface | null $containerInspectResponse */
            $containerInspectResponse = $this->dockerClient->containerInspect($this->id, [], $this->dockerClient::FETCH_RESPONSE);
            if ($containerInspectResponse === null) {
                throw new RuntimeException('Failed to inspect container: response is null');
            }

            // Decode the JSON response as an associative array
            $decodedResponse = json_decode(
                $containerInspectResponse->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($decodedResponse)) {
                throw new RuntimeException('Failed to inspect container: response is not a valid JSON object');
            }

            return $decodedResponse;
        } catch (JsonException $e) {
            throw new RuntimeException(
                sprintf('Failed to decode container inspect response: %s', $e->getMessage()),
                previous: $e
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Unexpected error while inspecting container: %s', $e->getMessage()),
                previous: $e
            );
        }
    }

    /**
     * @return array<string, mixed> An associative array containing the `NetworkSettings` details.
     * @throws RuntimeException If the container inspection is missing the `NetworkSettings` key.
     */
    protected function networkSettings(): array
    {
        $inspectData = $this->inspect();

        if (!isset($inspectData['NetworkSettings']) || !is_array($inspectData['NetworkSettings'])) {
            throw new RuntimeException('Missing or invalid NetworkSettings in container inspection');
        }

        return $inspectData['NetworkSettings'];
    }

    /**
     * @return array<string, array<array<string, string>>>
     * @throws RuntimeException
     */
    protected function ports(): array
    {
        /** @var array<string, array<array<string, string>>> $ports */
        $ports = $this->networkSettings()['Ports'] ?? [];

        if ($ports === []) {
            throw new RuntimeException('Failed to get ports from container');
        }

        return $ports;
    }
}
