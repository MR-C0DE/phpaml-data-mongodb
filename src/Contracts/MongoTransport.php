<?php

declare(strict_types=1);

namespace AML\Data\MongoDB\Contracts;

interface MongoTransport
{
    /**
     * @param array<string, mixed> $filter
     * @param array<string, 1|-1> $sort
     * @return list<array<string, mixed>>
     */
    public function find(string $database, string $collection, array $filter, array $sort = [], ?int $limit = null, int $skip = 0): array;
    /** @param array<string, mixed> $filter */
    public function count(string $database, string $collection, array $filter): int;
    /** @param array<string, mixed> $document */
    public function insert(string $database, string $collection, array $document): mixed;
    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $document
     */
    public function replace(string $database, string $collection, array $filter, array $document): int;
    /** @param array<string, mixed> $filter */
    public function delete(string $database, string $collection, array $filter): int;
    public function transaction(callable $operation): mixed;
    /** @return array<string, bool|string|null> */
    public function diagnostics(): array;
}
