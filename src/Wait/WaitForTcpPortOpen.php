<?php

declare(strict_types=1);

namespace Testcontainers\Wait;

/**
 * @deprecated Use WaitForHostPort instead
 * Kept for backward compatibility
 * Should be removed in next major version
 */
final class WaitForTcpPortOpen extends WaitForHostPort
{
    /**
     * @phpstan-ignore-next-line
     */
    public function __construct(int $port, string $network = null)
    {
        parent::__construct($port);
    }

    public static function make(int $port, ?string $network = null): self
    {
        return new self($port);
    }
}
