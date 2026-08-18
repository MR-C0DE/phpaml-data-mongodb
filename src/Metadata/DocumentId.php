<?php

declare(strict_types=1);

namespace AML\Data\MongoDB\Metadata;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class DocumentId
{
    public function __construct(public string $type = 'objectId')
    {
        if (!in_array($type, ['objectId', 'string'], true)) {
            throw new \InvalidArgumentException("Le type d'identifiant MongoDB doit être objectId ou string.");
        }
    }
}
