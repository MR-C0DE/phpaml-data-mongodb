<?php

declare(strict_types=1);

namespace AML\Data\MongoDB\Testing;

use AML\Data\MongoDB\Contracts\MongoTransport;
use Throwable;

final class MemoryMongoTransport implements MongoTransport
{
    /** @var array<string, array<string, list<array<string, mixed>>>> */ private array $data = [];
    private int $nextId = 1;
    /**
     * @param array<string, mixed> $filter
     * @param array<string, 1|-1> $sort
     * @return list<array<string, mixed>>
     */
    public function find(string $database, string $collection, array $filter, array $sort = [], ?int $limit = null, int $skip = 0): array
    {
        $rows = array_values(array_filter($this->data[$database][$collection] ?? [], fn (array $row): bool => $this->matches($row, $filter)));
        if ($sort !== []) usort($rows, static function (array $a, array $b) use ($sort): int { foreach ($sort as $field => $direction) { $result = ($a[$field] ?? null) <=> ($b[$field] ?? null); if ($result !== 0) return $result * $direction; } return 0; });
        return array_slice($rows, $skip, $limit);
    }
    public function count(string $database, string $collection, array $filter): int { return count($this->find($database, $collection, $filter)); }
    public function insert(string $database, string $collection, array $document): mixed { $id = $document['_id'] ?? (string) $this->nextId++; $document['_id'] = $id; $this->data[$database][$collection][] = $document; return $id; }
    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $document
     */
    public function replace(string $database, string $collection, array $filter, array $document): int { foreach ($this->data[$database][$collection] ?? [] as $index => $row) if ($this->matches($row, $filter)) { $this->data[$database][$collection][$index] = $document; return 1; } return 0; }
    public function delete(string $database, string $collection, array $filter): int { foreach ($this->data[$database][$collection] ?? [] as $index => $row) if ($this->matches($row, $filter)) { array_splice($this->data[$database][$collection], $index, 1); return 1; } return 0; }
    public function transaction(callable $operation): mixed { $snapshot = $this->data; try { return $operation(); } catch (Throwable $error) { $this->data = $snapshot; throw $error; } }
    public function diagnostics(): array { return ['connected' => true, 'transactions' => true, 'driver' => 'memory-mongodb']; }
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $filter
     */
    private function matches(array $row, array $filter): bool { foreach ($filter as $field => $condition) { $actual = $row[$field] ?? null; if (is_array($condition)) foreach ($condition as $operator => $expected) { $ok = match ($operator) { '$ne' => $actual !== $expected, '$gt' => $actual > $expected, '$gte' => $actual >= $expected, '$lt' => $actual < $expected, '$lte' => $actual <= $expected, '$in' => is_array($expected) && in_array($actual, $expected, true), default => false }; if (!$ok) return false; } elseif ($actual !== $condition) return false; } return true; }
}
