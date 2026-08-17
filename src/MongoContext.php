<?php

declare(strict_types=1);

namespace AML\Data\MongoDB;

use AML\Data\Entity;

abstract class MongoContext
{
    public function __construct(protected readonly MongoConnection $connection) {}
    /**
     * @template T of Entity
     * @param class-string<T> $entity
     * @return MongoSet<T>
     */
    final public function set(string $entity): MongoSet { return new MongoSet($this->connection, $entity); }
    final public function transaction(callable $operation): mixed { return $this->connection->transaction(fn (): mixed => $operation($this)); }
}
