<?php

declare(strict_types=1);

namespace Testcontainers\Utils;

use RuntimeException;

class DockerAuthConfig
{
    private const DEFAULT_CONFIG_PATHS = [
        '~/.docker/config.json',
        '/etc/docker/config.json',
    ];

    /**
     * @var array<string, array{auth?: string, username?: string, password?: string, email?: string}>
     */
    private array $auths = [];

    private ?string $credsStore = null;

    /**
     * @var array<string, string>
     */
    private array $credHelpers = [];

    public function __construct()
    {
        $this->loadConfig();
    }

    /**
     * Get authentication for a specific registry
     *
     * @return array{username: string, password: string}|null
     */
    public function getAuthForRegistry(string $registry): ?array
    {
        // Normalize registry URL
        $registry = $this->normalizeRegistry($registry);

        // Check if we have auth directly in config
        if (isset($this->auths[$registry])) {
            $auth = $this->auths[$registry];

            // If auth string is present, decode it
            if (isset($auth['auth'])) {
                $decoded = base64_decode($auth['auth'], true);
                if ($decoded === false) {
                    throw new RuntimeException('Invalid base64 auth string');
                }

                if (!str_contains($decoded, ':')) {
                    throw new RuntimeException('Invalid auth format');
                }
                [$username, $password] = explode(':', $decoded, 2);
                return ['username' => $username, 'password' => $password];
            }

            // If username/password are present directly
            if (isset($auth['username']) && isset($auth['password'])) {
                return ['username' => $auth['username'], 'password' => $auth['password']];
            }
        }

        // Check credential helpers
        if (isset($this->credHelpers[$registry])) {
            return $this->getCredentialsFromHelper($this->credHelpers[$registry], $registry);
        }

        // Check default credential store
        if ($this->credsStore !== null) {
            return $this->getCredentialsFromHelper($this->credsStore, $registry);
        }

        return null;
    }

    /**
     * Load Docker configuration from environment or default paths
     */
    private function loadConfig(): void
    {
        $configData = null;

        // First check DOCKER_AUTH_CONFIG environment variable
        $envConfig = getenv('DOCKER_AUTH_CONFIG');
        if ($envConfig !== false && $envConfig !== '') {
            $configData = json_decode($envConfig, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Invalid JSON in DOCKER_AUTH_CONFIG: ' . json_last_error_msg());
            }
        } else {
            // Try to load from default config files
            foreach (self::DEFAULT_CONFIG_PATHS as $path) {
                $expandedPath = str_replace('~', getenv('HOME') ?: '', $path);
                if (file_exists($expandedPath)) {
                    $content = file_get_contents($expandedPath);
                    if ($content === false) {
                        continue;
                    }

                    $configData = json_decode($content, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new RuntimeException("Invalid JSON in $expandedPath: " . json_last_error_msg());
                    }
                    break;
                }
            }
        }

        if (!is_array($configData)) {
            return;
        }

        // Parse the configuration
        if (isset($configData['auths']) && is_array($configData['auths'])) {
            /** @var array<string, array{auth?: string, username?: string, password?: string, email?: string}> $auths */
            $auths = $configData['auths'];
            $this->auths = $auths;
        }

        if (isset($configData['credsStore']) && is_string($configData['credsStore'])) {
            $this->credsStore = $configData['credsStore'];
        }

        if (isset($configData['credHelpers']) && is_array($configData['credHelpers'])) {
            /** @var array<string, string> $credHelpers */
            $credHelpers = $configData['credHelpers'];
            $this->credHelpers = $credHelpers;
        }
    }

    /**
     * Get credentials from a credential helper
     *
     * @return array{username: string, password: string}|null
     */
    private function getCredentialsFromHelper(string $helper, string $registry): ?array
    {
        $helperCommand = 'docker-credential-' . $helper;

        // Check if helper exists
        $checkCommand = sprintf('command -v %s 2>/dev/null', escapeshellarg($helperCommand));
        $helperPath = trim(shell_exec($checkCommand) ?: '');

        if (empty($helperPath)) {
            return null;
        }

        // Execute the credential helper
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open([$helperCommand, 'get'], $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException("Failed to execute credential helper: $helperCommand");
        }

        // Write the registry to stdin
        fwrite($pipes[0], $registry);
        fclose($pipes[0]);

        // Read the response
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            // Credentials not found is not an error
            if ($stderr !== false && strpos($stderr, 'credentials not found') !== false) {
                return null;
            }
            throw new RuntimeException("Credential helper failed: $stderr");
        }

        if ($stdout === false) {
            return null;
        }

        $credentials = json_decode($stdout, true);
        if (!is_array($credentials) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON from credential helper: ' . json_last_error_msg());
        }

        if (!isset($credentials['Username']) || !isset($credentials['Secret']) ||
            !is_string($credentials['Username']) || !is_string($credentials['Secret'])) {
            return null;
        }

        return [
            'username' => $credentials['Username'],
            'password' => $credentials['Secret'],
        ];
    }

    /**
     * Normalize registry URL to match Docker config format
     */
    private function normalizeRegistry(string $registry): string
    {
        // Remove protocol if present
        $normalized = preg_replace('#^https?://#', '', $registry);

        if ($normalized === null) {
            $normalized = $registry;
        }

        // Remove trailing slashes
        $normalized = rtrim($normalized, '/');

        // Docker Hub special case - normalize to the standard format
        if ($normalized === 'docker.io' || $normalized === 'index.docker.io' || $normalized === 'registry-1.docker.io') {
            return 'https://index.docker.io/v1/';
        }

        return $normalized;
    }

    /**
     * Get the registry from an image name
     */
    public static function getRegistryFromImage(string $image): string
    {
        // First, check if image has a registry prefix by looking for slashes
        $slashPos = strpos($image, '/');

        if ($slashPos === false) {
            // No slash means it's a Docker Hub official image
            return 'docker.io';
        }

        // Extract the potential registry part (everything before the first slash)
        $potentialRegistry = substr($image, 0, $slashPos);

        // Check if this looks like a registry:
        // - Contains a dot (domain name)
        // - Contains a colon (port specification)
        // - Is 'localhost' (special case)
        if (str_contains($potentialRegistry, '.') ||
            str_contains($potentialRegistry, ':') ||
            $potentialRegistry === 'localhost') {
            return $potentialRegistry;
        }

        // Otherwise, it's a Docker Hub image with a namespace
        return 'docker.io';
    }
}
