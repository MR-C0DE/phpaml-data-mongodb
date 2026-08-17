# phpaml/data-mongodb

Adaptateur MongoDB indépendant pour `phpaml/data`.

> État : `0.1.0-alpha.1`. Le transport mémoire et le transport officiel sont validés, notamment contre MongoDB 8.2 en replica set.

```bash
composer require phpaml/data-mongodb:^0.1@alpha
```

## Installation PHPAML

```bash
aml install data --driver mongodb
```

Configuration :

```dotenv
DATA_DRIVER=mongodb
DATA_URI=mongodb://127.0.0.1:27017
DATA_DATABASE=app
```

Le package requiert `mongodb/mongodb` et `ext-mongodb` pour le transport officiel. Lorsqu'il est présent dans l'autoload, `ConnectionManager` découvre automatiquement `MongoDriverAdapter`.

## Documents et contexte

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
```

## Requêtes

```php
$page = $db->users()
    ->where('age', '>=', 18)
    ->orderBy('name')
    ->paginate(1, 20);

$user = $db->users()->find($id);
$db->users()->add($user);
$db->users()->update($user);
$db->users()->remove($user);
```

Opérateurs portables disponibles : `=`, `!=`, `>`, `>=`, `<`, `<=` et `in`.

## Transactions et diagnostic

```php
$db->transaction(function (AppMongoContext $db): void {
    // Les opérations partagent une session MongoDB.
});
```

Les transactions du serveur exigent une topologie MongoDB compatible, généralement un replica set. Le transport mémoire fournit un rollback déterministe pour les tests.

```bash
aml data:doctor
```

## Tests serveur

```bash
AML_DATA_MONGODB_URI='mongodb://127.0.0.1:27017' \
AML_DATA_MONGODB_DATABASE='phpaml_data_test' \
php tests/server.php
```
