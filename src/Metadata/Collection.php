<?php

declare(strict_types=1);

namespace AML\Data\MongoDB\Metadata;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Collection
{
    public function __construct(public string $name) {}
}
