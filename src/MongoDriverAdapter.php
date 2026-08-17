<?php

declare(strict_types=1);

namespace AML\Data\MongoDB;

use AML\Data\Connections\DriverAdapter;
use AML\Data\MongoDB\Transport\OfficialMongoTransport;
use InvalidArgumentException;

final class MongoDriverAdapter implements DriverAdapter
{
    public function connect(array $config, string $projectRoot): MongoConnection
    {
        $uri = $config['uri'] ?? 'mongodb://127.0.0.1:27017';
        $database = $config['database'] ?? null;
        if (!is_string($uri) || !is_string($database) || $database === '') throw new InvalidArgumentException('MongoDB exige uri et database.');
        return new MongoConnection(new OfficialMongoTransport($uri), $database);
    }
}
