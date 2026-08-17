<?php

declare(strict_types=1);

namespace AML\Data\MongoDB;

use AML\Data\Entity;
use AML\Data\MongoDB\Metadata\DocumentMetadata;
use AML\Data\Pagination\Page;
use InvalidArgumentException;

/** @template T of Entity */
final class MongoSet
{
    /** @var array<string, mixed> */ private array $filter = [];
    /** @var array<string, 1|-1> */ private array $sort = [];
    private ?int $limit = null; private int $skip = 0;
    /** @var DocumentMetadata<T> */ private readonly DocumentMetadata $metadata;

    /** @param class-string<T> $entity */
    public function __construct(private readonly MongoConnection $connection, string $entity) { $this->metadata = DocumentMetadata::from($entity); }

    /** @return self<T> */
    public function where(string $field, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $operators = ['=' => '$eq', '!=' => '$ne', '>' => '$gt', '>=' => '$gte', '<' => '$lt', '<=' => '$lte', 'in' => '$in'];
        $mongo = $operators[strtolower($operator)] ?? throw new InvalidArgumentException("Opérateur MongoDB inconnu : {$operator}");
        $clone->filter[$field] = $mongo === '$eq' ? $value : [$mongo => $value];
        return $clone;
    }

    /** @return self<T> */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $clone = clone $this; $clone->sort[$field] = strtolower($direction) === 'desc' ? -1 : 1; return $clone;
    }

    /** @return self<T> */
    public function limit(int $limit, int $skip = 0): self
    {
        if ($limit < 1 || $skip < 0) throw new InvalidArgumentException('Limite MongoDB invalide.');
        $clone = clone $this; $clone->limit = $limit; $clone->skip = $skip; return $clone;
    }

    /** @return list<T> */
    public function all(): array
    {
        $documents = $this->connection->transport->find($this->connection->database, $this->metadata->collection, $this->filter, $this->sort, $this->limit, $this->skip);
        $entities = [];
        foreach ($documents as $document) $entities[] = $this->hydrate($document);
        return $entities;
    }

    /** @return T|null */ public function first(): ?Entity { return $this->limit(1)->all()[0] ?? null; }
    /** @return T|null */ public function find(mixed $id): ?Entity { return $this->where($this->metadata->key, '=', $id)->first(); }
    public function count(): int { return $this->connection->transport->count($this->connection->database, $this->metadata->collection, $this->filter); }
    /** @return Page<T> */ public function paginate(int $page = 1, int $perPage = 15): Page { return new Page($this->limit($perPage, ($page - 1) * $perPage)->all(), $this->count(), $page, $perPage); }

    /** @param T $entity @return T */
    public function add(Entity $entity): Entity
    {
        $document = $this->metadata->extract($entity, false);
        $id = $this->connection->transport->insert($this->connection->database, $this->metadata->collection, $document);
        $property = $this->metadata->properties[$this->metadata->key]; if (!$property->isInitialized($entity)) $property->setValue($entity, $id);
        return $entity;
    }

    /** @param T $entity */
    public function update(Entity $entity): void
    {
        $property = $this->metadata->properties[$this->metadata->key];
        if (!$property->isInitialized($entity)) throw new InvalidArgumentException('Document sans identifiant.');
        $this->connection->transport->replace($this->connection->database, $this->metadata->collection, [$this->metadata->key => $property->getValue($entity)], $this->metadata->extract($entity));
    }

    /** @param T $entity */
    public function remove(Entity $entity): void
    {
        $property = $this->metadata->properties[$this->metadata->key];
        if (!$property->isInitialized($entity)) throw new InvalidArgumentException('Document sans identifiant.');
        $this->connection->transport->delete($this->connection->database, $this->metadata->collection, [$this->metadata->key => $property->getValue($entity)]);
    }

    /**
     * @param array<string, mixed> $document
     * @return T
     */
    private function hydrate(array $document): Entity { return $this->metadata->hydrate($document); }
}
