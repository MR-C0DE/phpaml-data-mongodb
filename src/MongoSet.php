<?php

declare(strict_types=1);

namespace AML\Data\MongoDB;

use AML\Data\Entity;
use AML\Data\MongoDB\Metadata\DocumentMetadata;
use AML\Data\Pagination\Page;
use InvalidArgumentException;
use AML\Data\Validation\EntityValidator;

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
        $this->assertField($field);
        $operators = ['=' => '$eq', '!=' => '$ne', '>' => '$gt', '>=' => '$gte', '<' => '$lt', '<=' => '$lte', 'in' => '$in'];
        $mongo = $operators[strtolower($operator)] ?? throw new InvalidArgumentException("Opérateur MongoDB inconnu : {$operator}");
        if ($field === $this->metadata->key) $value = $this->normalizeKey($value);
        $condition = $mongo === '$eq' ? $value : [$mongo => $value];
        if (array_key_exists($field, $clone->filter)) {
            $previous = $clone->filter[$field];
            unset($clone->filter[$field]);
            $clone->filter['$and'] = array_merge(
                is_array($clone->filter['$and'] ?? null) ? $clone->filter['$and'] : [],
                [[$field => $previous], [$field => $condition]],
            );
        } else {
            $clone->filter[$field] = $condition;
        }
        return $clone;
    }

    /** @return self<T> */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $this->assertField($field);
        $direction = strtolower($direction);
        if (!in_array($direction, ['asc', 'desc'], true)) throw new InvalidArgumentException('Direction MongoDB invalide. Utilisez asc ou desc.');
        $clone = clone $this; $clone->sort[$field] = $direction === 'desc' ? -1 : 1; return $clone;
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
    /** @return Page<T> */ public function paginate(int $page = 1, int $perPage = 15): Page
    {
        if ($page < 1 || $perPage < 1 || $perPage > 1000) throw new InvalidArgumentException('Pagination MongoDB invalide.');
        return new Page($this->limit($perPage, ($page - 1) * $perPage)->all(), $this->count(), $page, $perPage);
    }

    /** @param T $entity @return T */
    public function add(Entity $entity): Entity
    {
        (new EntityValidator())->validate($entity);
        $property = $this->metadata->properties[$this->metadata->key];
        $hasExplicitId = $property->isInitialized($entity);
        $document = $this->metadata->extract($entity, $hasExplicitId);
        if ($hasExplicitId) $document[$this->metadata->key] = $this->normalizeKey($document[$this->metadata->key]);
        $id = $this->connection->transport->insert($this->connection->database, $this->metadata->collection, $document);
        if (!$hasExplicitId) $property->setValue($entity, $id);
        return $entity;
    }

    /** @param T $entity */
    public function update(Entity $entity): void
    {
        (new EntityValidator())->validate($entity);
        $property = $this->metadata->properties[$this->metadata->key];
        if (!$property->isInitialized($entity)) throw new InvalidArgumentException('Document sans identifiant.');
        $document = $this->metadata->extract($entity);
        $document[$this->metadata->key] = $this->normalizeKey($document[$this->metadata->key]);
        $this->connection->transport->replace($this->connection->database, $this->metadata->collection, [$this->metadata->key => $this->normalizeKey($property->getValue($entity))], $document);
    }

    /** @param T $entity */
    public function remove(Entity $entity): void
    {
        $property = $this->metadata->properties[$this->metadata->key];
        if (!$property->isInitialized($entity)) throw new InvalidArgumentException('Document sans identifiant.');
        $this->connection->transport->delete($this->connection->database, $this->metadata->collection, [$this->metadata->key => $this->normalizeKey($property->getValue($entity))]);
    }

    /**
     * @param array<string, mixed> $document
     * @return T
     */
    private function hydrate(array $document): Entity { return $this->metadata->hydrate($document); }

    private function assertField(string $field): void
    {
        if (!array_key_exists($field, $this->metadata->properties)) {
            throw new InvalidArgumentException("Champ MongoDB inconnu : {$field}");
        }
    }

    private function normalizeKey(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeKey($item), $value);
        }
        if ($this->metadata->keyType === 'string') return $value;
        $class = 'MongoDB\\BSON\\ObjectId';
        if (is_object($value) && $value::class === $class) return $value;
        if (!is_string($value) || preg_match('/^[a-f0-9]{24}$/i', $value) !== 1) {
            throw new InvalidArgumentException("Un identifiant objectId doit être une chaîne hexadécimale de 24 caractères ou un MongoDB\\BSON\\ObjectId.");
        }
        return class_exists($class) ? new $class($value) : $value;
    }
}
