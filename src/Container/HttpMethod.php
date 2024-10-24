<?php

declare(strict_types=1);

namespace Testcontainers\Container;

enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case DELETE = 'DELETE';
    case HEAD = 'HEAD';
    case OPTIONS = 'OPTIONS';

    public static function fromString(string $method): self
    {
        return match (strtoupper($method)) {
            'GET' => self::GET,
            'POST' => self::POST,
            'PUT' => self::PUT,
            'DELETE' => self::DELETE,
            'HEAD' => self::HEAD,
            'OPTIONS' => self::OPTIONS,
            default => throw new \InvalidArgumentException("Invalid HTTP method: $method"),
        };
    }
}
