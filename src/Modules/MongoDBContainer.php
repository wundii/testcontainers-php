<?php

declare(strict_types=1);

namespace Testcontainers\Modules;

use Testcontainers\Container\GenericContainer;
use Testcontainers\Wait\WaitForExec;

class MongoDBContainer extends GenericContainer
{
    private const STARTUP_TIMEOUT_MS = 30_000;

    public function __construct(
        string $version = 'latest',
        public readonly string $username = 'test',
        public readonly string $password = 'test',
        public readonly string $database = 'test',
    ) {
        parent::__construct('mongo:' . $version);

        $this
            ->withExposedPorts("27017/tcp")
            ->withEnvironment([
                "MONGO_INITDB_ROOT_USERNAME" => $this->username,
                "MONGO_INITDB_ROOT_PASSWORD" => $this->password,
                "MONGO_INITDB_DATABASE" => $this->database,
            ])
            ->withWait(new WaitForExec([
                'mongosh', 'admin',
                '-u', $this->username,
                '-p', $this->password,
                '--eval', "'show dbs'",
            ], null, self::STARTUP_TIMEOUT_MS));
    }
}
