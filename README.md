# PHPAML Data MongoDB

The official MongoDB adapter for PHPAML Data.

> Status: `0.1.0-alpha.5`. The in-memory and official transports are tested
> against MongoDB 8.2 running as a replica set.

[Documentation française](README.fr.md) · [PHPAML Data](https://github.com/MR-C0DE/phpaml-data) ·
[Changelog](CHANGELOG.md)

## Why this adapter?

PHPAML Data MongoDB brings typed PHPAML entities and a familiar query workflow
to MongoDB while preserving MongoDB-specific behavior. It does not pretend that
a document database is relational SQL.

- typed documents and collections;
- CRUD, filters, ordering, and pagination;
- explicit `objectId` and string identifier contracts;
- entity validation inherited from PHPAML Data;
- transactions through MongoDB sessions;
- automatic discovery by PHPAML's `ConnectionManager`;
- deterministic in-memory transport for tests;
- topology-aware diagnostics.

## Install

In a PHPAML project:

```bash
aml install data --driver mongodb
```

In any Composer project:

```bash
composer require phpaml/data:^0.2@alpha phpaml/data-mongodb:^0.1@alpha
```

The official transport requires `ext-mongodb` and `mongodb/mongodb`.

## Five-minute example

```php
use AML\Data\Entity;
use AML\Data\MongoDB\Metadata\{Collection, DocumentId};
use AML\Data\MongoDB\{MongoContext, MongoSet};

#[Collection('users')]
final class User extends Entity
{
    #[DocumentId]
    public string $id;

    public string $name;
    public int $age;
}

final class AppMongoContext extends MongoContext
{
    /** @return MongoSet<User> */
    public function users(): MongoSet
    {
        return $this->set(User::class);
    }
}

$page = $db->users()
    ->where('age', '>=', 18)
    ->orderBy('name')
    ->paginate(page: 1, perPage: 20);
```

Supported portable operators are `=`, `!=`, `>`, `>=`, `<`, `<=`, and `in`.

## Identifier contracts

`#[DocumentId]` uses MongoDB `ObjectId` by default. PHPAML exposes it as a
string in the entity and converts it for database operations. Use an explicit
string contract when the identifier must remain textual:

```php
#[DocumentId(type: 'string')]
public string $id;
```

## Platform relationship

```text
PHPAML Data
  └─ defines shared entities, validation, and connection discovery
       └─ PHPAML Data MongoDB
            ├─ MongoContext and MongoSet
            ├─ official MongoDB transport
            └─ in-memory test transport
```

The adapter is discovered automatically when it is present in Composer's
autoload. Use `aml data:doctor` to inspect the configured server and transaction
capabilities.

## Current scope

This alpha intentionally does not claim SQL feature parity. Public document
relations, declarative indexes, document migrations, and aggregation pipelines
remain future work. Server transactions require a compatible MongoDB topology,
normally a replica set.

## Tests

```bash
composer test

AML_DATA_MONGODB_URI='mongodb://127.0.0.1:27017' \
AML_DATA_MONGODB_DATABASE='phpaml_data_test' \
php tests/server.php
```

## License

PHPAML Data MongoDB is open-source software licensed under the [MIT License](LICENSE).
