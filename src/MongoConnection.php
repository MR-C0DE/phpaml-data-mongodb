<?php

declare(strict_types=1);

namespace AML\Data\MongoDB;

use AML\Data\MongoDB\Contracts\MongoTransport;
use AML\Data\Connections\InspectableConnection;

final readonly class MongoConnection implements InspectableConnection
{
    public function __construct(public MongoTransport $transport, public string $database) {}
    public function transaction(callable $operation): mixed { return $this->transport->transaction(fn (): mixed => $operation($this)); }
    public function diagnostics(): array { return $this->transport->diagnostics(); }
}
