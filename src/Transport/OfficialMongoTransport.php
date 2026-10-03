<?php

declare(strict_types=1);

namespace AML\Data\MongoDB\Transport;

use AML\Data\MongoDB\Contracts\MongoTransport;
use RuntimeException;
use Throwable;

final class OfficialMongoTransport implements MongoTransport
{
    private object $client;
    private ?object $session = null;

    public function __construct(string $uri)
    {
        $class = 'MongoDB\\Client';
        if (!class_exists($class)) throw new RuntimeException("MongoDB est indisponible. Installez mongodb/mongodb et ext-mongodb.");
        $client = new $class($uri);
        $this->client = $client;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, 1|-1> $sort
     * @return list<array<string, mixed>>
     */
    public function find(string $database, string $collection, array $filter, array $sort = [], ?int $limit = null, int $skip = 0): array
    {
        $options = ['sort' => $sort, 'skip' => $skip] + $this->sessionOptions();
        if ($limit !== null) $options['limit'] = $limit;
        $cursor = $this->invoke($this->collection($database, $collection), 'find', [$filter, $options]);
        if (!is_iterable($cursor)) throw new RuntimeException('Le curseur MongoDB est invalide.');
        $documents = [];
        foreach ($cursor as $document) $documents[] = $this->document($document);
        return $documents;
    }

    public function count(string $database, string $collection, array $filter): int
    {
        $value = $this->invoke($this->collection($database, $collection), 'countDocuments', [$filter, $this->sessionOptions()]);
        if (!is_int($value)) throw new RuntimeException('Le total MongoDB est invalide.');
        return $value;
    }

    public function insert(string $database, string $collection, array $document): mixed
    {
        $result = $this->invoke($this->collection($database, $collection), 'insertOne', [$document, $this->sessionOptions()]);
        if (!is_object($result)) throw new RuntimeException('Résultat insertOne invalide.');
        return $this->normalizeValue($this->invoke($result, 'getInsertedId'));
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $document
     */
    public function replace(string $database, string $collection, array $filter, array $document): int
    {
        $result = $this->invoke($this->collection($database, $collection), 'replaceOne', [$filter, $document, $this->sessionOptions()]);
        if (!is_object($result)) throw new RuntimeException('Résultat replaceOne invalide.');
        $count = $this->invoke($result, 'getModifiedCount');
        return is_int($count) ? $count : 0;
    }

    public function delete(string $database, string $collection, array $filter): int
    {
        $result = $this->invoke($this->collection($database, $collection), 'deleteOne', [$filter, $this->sessionOptions()]);
        if (!is_object($result)) throw new RuntimeException('Résultat deleteOne invalide.');
        $count = $this->invoke($result, 'getDeletedCount');
        return is_int($count) ? $count : 0;
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->session !== null) {
            throw new RuntimeException('Les transactions MongoDB imbriquées ne sont pas prises en charge.');
        }
        $session = $this->invoke($this->client, 'startSession');
        if (!is_object($session)) throw new RuntimeException('Session MongoDB invalide.');
        $this->session = $session;
        $started = false;
        try {
            $this->invoke($session, 'startTransaction');
            $started = true;
            $result = $operation();
            $this->invoke($session, 'commitTransaction');
            return $result;
        }
        catch (Throwable $error) {
            if ($started) $this->invoke($session, 'abortTransaction');
            throw $error;
        }
        finally { $this->session = null; }
    }

    public function diagnostics(): array
    {
        try {
            $admin = $this->invoke($this->client, 'selectDatabase', ['admin']);
            if (!is_object($admin)) throw new RuntimeException('Base admin MongoDB invalide.');
            $ping = $this->commandDocument($admin, ['ping' => 1]);
            if (($ping['ok'] ?? 0) != 1) throw new RuntimeException('Le ping MongoDB a échoué.');
            $hello = $this->commandDocument($admin, ['hello' => 1]);
            $transactions = (isset($hello['setName']) && is_string($hello['setName']) && $hello['setName'] !== '')
                || (($hello['msg'] ?? null) === 'isdbgrid');
            return ['connected' => true, 'transactions' => $transactions, 'driver' => 'mongodb'];
        } catch (Throwable $error) {
            return ['connected' => false, 'transactions' => false, 'driver' => 'mongodb', 'error' => $error->getMessage()];
        }
    }

    private function collection(string $database, string $collection): object
    {
        $value = $this->invoke($this->client, 'selectCollection', [$database, $collection]);
        if (!is_object($value)) throw new RuntimeException('Collection MongoDB invalide.');
        return $value;
    }
    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function commandDocument(object $database, array $command): array
    {
        $result = $this->invoke($database, 'command', [$command]);
        if (!is_object($result) || !method_exists($result, 'toArray')) throw new RuntimeException('Réponse de commande MongoDB invalide.');
        $rows = $this->invoke($result, 'toArray');
        if (!is_array($rows) || !isset($rows[0])) throw new RuntimeException('Réponse de commande MongoDB vide.');
        return $this->document($rows[0]);
    }

    /** @return array<string, object> */ private function sessionOptions(): array { return $this->session === null ? [] : ['session' => $this->session]; }
    /** @param list<mixed> $arguments */
    private function invoke(object $object, string $method, array $arguments = []): mixed
    {
        if (!is_callable([$object, $method])) throw new RuntimeException("Méthode MongoDB indisponible : {$method}");
        return call_user_func_array([$object, $method], $arguments);
    }
    /** @return array<string, mixed> */
    private function document(mixed $value): array
    {
        $raw = match (true) {
            is_array($value) => $value,
            $value instanceof \Traversable => iterator_to_array($value),
            is_object($value) => get_object_vars($value),
            default => throw new RuntimeException('Document MongoDB invalide.'),
        };
        $document = [];
        foreach ($raw as $key => $item) {
            if (!is_string($key)) throw new RuntimeException('Clé de document MongoDB invalide.');
            $document[$key] = $this->normalizeValue($item);
        }
        return $document;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_object($value) && $value::class === 'MongoDB\\BSON\\ObjectId') {
            $normalized = $this->invoke($value, '__toString');
            if (!is_string($normalized)) throw new RuntimeException('ObjectId MongoDB invalide.');
            return $normalized;
        }
        if (is_object($value) && is_callable([$value, 'getArrayCopy'])) {
            $copy = $this->invoke($value, 'getArrayCopy');
            if (is_array($copy)) $value = $copy;
        }
        if ($value instanceof \Traversable) $value = iterator_to_array($value);
        if (!is_array($value)) return $value;
        $normalized = [];
        foreach ($value as $key => $item) $normalized[$key] = $this->normalizeValue($item);
        return $normalized;
    }
}
