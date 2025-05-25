<?php

declare(strict_types=1);

namespace Testcontainers\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use Testcontainers\Utils\DockerAuthConfig;
use RuntimeException;

class DockerAuthConfigTest extends TestCase
{
    private string $originalHome;
    private ?string $originalAuthConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalHome = getenv('HOME') ?: '';
        $this->originalAuthConfig = getenv('DOCKER_AUTH_CONFIG') ?: null;

        // Clear the environment variable
        putenv('DOCKER_AUTH_CONFIG');
    }

    protected function tearDown(): void
    {
        // Restore original values
        putenv('HOME=' . $this->originalHome);
        if ($this->originalAuthConfig !== null) {
            putenv('DOCKER_AUTH_CONFIG=' . $this->originalAuthConfig);
        } else {
            putenv('DOCKER_AUTH_CONFIG');
        }
        parent::tearDown();
    }

    public function testLoadConfigFromEnvironmentVariable(): void
    {
        $config = [
            'auths' => [
                'https://index.docker.io/v1/' => [
                    'auth' => base64_encode('user:pass'),
                ],
            ],
        ];

        putenv('DOCKER_AUTH_CONFIG=' . json_encode($config));

        $authConfig = new DockerAuthConfig();
        $creds = $authConfig->getAuthForRegistry('docker.io');

        $this->assertNotNull($creds);
        $this->assertEquals('user', $creds['username']);
        $this->assertEquals('pass', $creds['password']);
    }

    public function testLoadConfigWithUsernamePassword(): void
    {
        $config = [
            'auths' => [
                'myregistry.com' => [
                    'username' => 'myuser',
                    'password' => 'mypass',
                ],
            ],
        ];

        putenv('DOCKER_AUTH_CONFIG=' . json_encode($config));

        $authConfig = new DockerAuthConfig();
        $creds = $authConfig->getAuthForRegistry('myregistry.com');

        $this->assertNotNull($creds);
        $this->assertEquals('myuser', $creds['username']);
        $this->assertEquals('mypass', $creds['password']);
    }

    public function testGetAuthForUnknownRegistry(): void
    {
        $config = [
            'auths' => [
                'docker.io' => [
                    'auth' => base64_encode('user:pass'),
                ],
            ],
        ];

        putenv('DOCKER_AUTH_CONFIG=' . json_encode($config));

        $authConfig = new DockerAuthConfig();
        $creds = $authConfig->getAuthForRegistry('unknown.registry.com');

        $this->assertNull($creds);
    }

    public function testInvalidJsonInEnvironmentVariable(): void
    {
        putenv('DOCKER_AUTH_CONFIG=invalid json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON in DOCKER_AUTH_CONFIG');

        new DockerAuthConfig();
    }

    public function testInvalidBase64Auth(): void
    {
        // Use a string that decodes to valid base64 but doesn't contain a colon
        $config = [
            'auths' => [
                'https://index.docker.io/v1/' => [
                    'auth' => base64_encode('noColonInThis'),
                ],
            ],
        ];

        putenv('DOCKER_AUTH_CONFIG=' . json_encode($config));

        $authConfig = new DockerAuthConfig();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid auth format');

        $authConfig->getAuthForRegistry('docker.io');
    }

    public function testGetRegistryFromImage(): void
    {
        // Test various image formats
        $this->assertEquals('docker.io', DockerAuthConfig::getRegistryFromImage('ubuntu'));
        $this->assertEquals('docker.io', DockerAuthConfig::getRegistryFromImage('library/ubuntu'));
        $this->assertEquals('docker.io', DockerAuthConfig::getRegistryFromImage('ubuntu:20.04'));
        $this->assertEquals('ghcr.io', DockerAuthConfig::getRegistryFromImage('ghcr.io/owner/repo'));
        $this->assertEquals('ghcr.io', DockerAuthConfig::getRegistryFromImage('ghcr.io/owner/repo:tag'));
        $this->assertEquals('myregistry.com', DockerAuthConfig::getRegistryFromImage('myregistry.com/image'));
        $this->assertEquals('localhost:5000', DockerAuthConfig::getRegistryFromImage('localhost:5000/image'));
    }

    public function testNormalizeRegistry(): void
    {
        $config = [
            'auths' => [
                'https://index.docker.io/v1/' => [
                    'auth' => base64_encode('user:pass'),
                ],
            ],
        ];

        putenv('DOCKER_AUTH_CONFIG=' . json_encode($config));

        $authConfig = new DockerAuthConfig();

        // All these should resolve to the same Docker Hub entry
        $registries = ['docker.io', 'index.docker.io', 'registry-1.docker.io'];

        foreach ($registries as $registry) {
            $creds = $authConfig->getAuthForRegistry($registry);
            $this->assertNotNull($creds, "Failed to get auth for $registry");
            $this->assertEquals('user', $creds['username']);
            $this->assertEquals('pass', $creds['password']);
        }
    }

    public function testEmptyConfig(): void
    {
        putenv('DOCKER_AUTH_CONFIG={}');

        $authConfig = new DockerAuthConfig();
        $creds = $authConfig->getAuthForRegistry('docker.io');

        $this->assertNull($creds);
    }

    public function testLoadConfigFromFile(): void
    {
        // Create a temporary directory for testing
        $tempDir = sys_get_temp_dir() . '/testcontainers_test_' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/.docker');

        $config = [
            'auths' => [
                'fileregistry.com' => [
                    'auth' => base64_encode('fileuser:filepass'),
                ],
            ],
        ];

        file_put_contents($tempDir . '/.docker/config.json', json_encode($config));

        // Set HOME to our temp directory
        putenv('HOME=' . $tempDir);

        $authConfig = new DockerAuthConfig();
        $creds = $authConfig->getAuthForRegistry('fileregistry.com');

        $this->assertNotNull($creds);
        $this->assertEquals('fileuser', $creds['username']);
        $this->assertEquals('filepass', $creds['password']);

        // Cleanup
        unlink($tempDir . '/.docker/config.json');
        rmdir($tempDir . '/.docker');
        rmdir($tempDir);
    }
}
