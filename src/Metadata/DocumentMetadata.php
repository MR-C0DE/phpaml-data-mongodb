<?php

declare(strict_types=1);

namespace AML\Data\MongoDB\Metadata;

use AML\Data\Entity;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;

/** @template T of Entity */
final readonly class DocumentMetadata
{
    /**
     * @param class-string<T> $class
     * @param array<string, ReflectionProperty> $properties
     */
    private function __construct(public string $class, public string $collection, public array $properties, public string $key, public string $keyType) {}

    /**
     * @template E of Entity
     * @param class-string<E> $class
     * @return self<E>
     */
    public static function from(string $class): self
    {
        if (!is_subclass_of($class, Entity::class)) throw new InvalidArgumentException("{$class} doit étendre " . Entity::class . '.');
        $reflection = new ReflectionClass($class);
        $attribute = $reflection->getAttributes(Collection::class)[0] ?? null;
        $collection = $attribute?->newInstance()->name ?? strtolower($reflection->getShortName()) . 's';
        self::identifier($collection);
        $properties = []; $key = null; $keyType = 'objectId';
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) continue;
            $field = $property->getAttributes(Field::class)[0] ?? null;
            $name = $property->getAttributes(DocumentId::class) !== [] || in_array($property->getName(), ['id', '_id'], true)
                ? '_id' : ($field?->newInstance()->name ?? $property->getName());
            self::identifier($name);
            $properties[$name] = $property;
            if ($name === '_id') {
                $key = $name;
                $idAttribute = $property->getAttributes(DocumentId::class)[0] ?? null;
                $keyType = $idAttribute?->newInstance()->type ?? 'objectId';
            }
        }
        if ($key === null) throw new InvalidArgumentException("Le document {$class} doit exposer id, _id ou #[DocumentId].");
        return new self($class, $collection, $properties, $key, $keyType);
    }

    /**
     * @param array<string, mixed> $document
     * @return T
     */
    public function hydrate(array $document): Entity
    {
        $entity = (new ReflectionClass($this->class))->newInstanceWithoutConstructor();
        foreach ($this->properties as $field => $property) if (array_key_exists($field, $document)) $property->setValue($entity, $document[$field]);
        return $entity;
    }

    /** @return array<string, mixed> */
    public function extract(Entity $entity, bool $includeKey = true): array
    {
        $document = [];
        foreach ($this->properties as $field => $property) {
            if (!$includeKey && $field === $this->key) continue;
            if ($property->isInitialized($entity)) $document[$field] = $property->getValue($entity);
        }
        return $document;
    }

    private static function identifier(string $value): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) throw new InvalidArgumentException("Nom MongoDB invalide : {$value}");
    }
}
