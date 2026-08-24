# phpaml/data-mongodb

Adaptateur MongoDB indépendant pour `phpaml/data`.

> État : `0.1.0-alpha.4`. Le transport mémoire et le transport officiel sont validés automatiquement contre MongoDB 8.2 en replica set.

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

## Identifiants

`#[DocumentId]` utilise le contrat `objectId` par défaut : PHPAML expose
l’identifiant comme une chaîne dans l’entité et le convertit en
`MongoDB\BSON\ObjectId` pour les opérations MongoDB.

Pour conserver une clé textuelle — même si elle contient exactement 24
caractères hexadécimaux — déclarez-la explicitement :

```php
#[DocumentId(type: 'string')]
public string $id;
```

Les transactions MongoDB imbriquées sont refusées avec une exception claire.

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
Les filtres répétés sur le même champ sont combinés, les champs sont contrôlés
contre les métadonnées du document, et les directions de tri ou paramètres de
pagination invalides sont refusés. La validation d’entité de `phpaml/data`
s’applique avant les insertions et mises à jour.

Cette version alpha ne présente pas MongoDB comme l’équivalent fonctionnel de
la couche SQL. Les relations, index déclaratifs, migrations de documents et
pipelines d’agrégation publics restent à concevoir. Cassandra restera un
adaptateur distinct afin de respecter son propre modèle de données.

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
