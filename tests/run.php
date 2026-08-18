<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AML\Data\Connections\ConnectionManager;
use AML\Data\Connections\DriverAdapter;
use AML\Data\Entity;
use AML\Data\MongoDB\Metadata\{Collection, DocumentId};
use AML\Data\MongoDB\{MongoConnection, MongoContext};
use AML\Data\MongoDB\Testing\MemoryMongoTransport;
use AML\Data\MongoDB\Transport\OfficialMongoTransport;

#[Collection('users')]
final class MongoUser extends Entity
{
    #[DocumentId] public string $id;
    public string $name;
    public int $age;
}

#[Collection('string_keys')]
final class StringKeyDocument extends Entity
{
    #[DocumentId(type: 'string')] public string $id;
    public string $value;
}

final class TestMongoContext extends MongoContext
{
    /** @return \AML\Data\MongoDB\MongoSet<MongoUser> */
    public function users(): \AML\Data\MongoDB\MongoSet { return $this->set(MongoUser::class); }
    /** @return \AML\Data\MongoDB\MongoSet<StringKeyDocument> */
    public function stringKeys(): \AML\Data\MongoDB\MongoSet { return $this->set(StringKeyDocument::class); }
}

final class FailingMongoSession
{
    public int $starts = 0;
    public function startTransaction(): void { $this->starts++; throw new RuntimeException('start failed'); }
}

final class FakeMongoCursor
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private array $rows) {}
    /** @return list<array<string, mixed>> */
    public function toArray(): array { return $this->rows; }
}

final class FakeMongoDatabase
{
    public function __construct(private bool $replicaSet) {}
    /** @param array<string, mixed> $command */
    public function command(array $command): FakeMongoCursor
    {
        return new FakeMongoCursor(isset($command['hello'])
            ? [['ok' => 1, 'setName' => $this->replicaSet ? 'rs0' : null]]
            : [['ok' => 1]]);
    }
}

final class FakeMongoClient
{
    public function __construct(public FailingMongoSession $session, private bool $replicaSet = false) {}
    public function startSession(): FailingMongoSession { return $this->session; }
    public function selectDatabase(string $name): FakeMongoDatabase { return new FakeMongoDatabase($this->replicaSet); }
}

/** @param object $client */
$officialTransport = static function (object $client): OfficialMongoTransport {
    $reflection = new ReflectionClass(OfficialMongoTransport::class);
    $transport = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('client')->setValue($transport, $client);
    return $transport;
};

$tests = [];
$test = static function (string $name, Closure $case) use (&$tests): void { $tests[$name] = $case; };
$expect = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$test('MongoSet fournit CRUD, requêtes et pagination', function () use ($expect): void {
    $context = new TestMongoContext(new MongoConnection(new MemoryMongoTransport(), 'app'));
    foreach ([['Ada', 36], ['Grace', 44], ['Linus', 28]] as [$name, $age]) { $user = new MongoUser(); $user->name = $name; $user->age = $age; $context->users()->add($user); }
    $page = $context->users()->where('age', '>=', 30)->orderBy('age', 'desc')->paginate(1, 2);
    $expect($page->total === 2 && $page->items[0]->name === 'Grace', 'La requête MongoDB est incorrecte.');
    $range = $context->users()->where('age', '>=', 30)->where('age', '<', 40)->all();
    $expect(count($range) === 1 && $range[0]->name === 'Ada', 'Les filtres répétés sur un champ doivent être combinés.');
    foreach ([fn () => $context->users()->orderBy('age', 'sideways'), fn () => $context->users()->paginate(0, 10), fn () => $context->users()->where('unknown', '=', 1)] as $invalid) {
        try { $invalid(); throw new RuntimeException('Une requête MongoDB invalide a été acceptée.'); } catch (InvalidArgumentException) {}
    }
    $adaId = str_pad('1', 24, '0', STR_PAD_LEFT);
    $ada = $context->users()->find($adaId); $expect($ada instanceof MongoUser && $ada->name === 'Ada', "L'hydratation MongoDB a échoué.");
    $ada->name = 'Ada Lovelace'; $context->users()->update($ada);
    $expect($context->users()->find($adaId)?->name === 'Ada Lovelace', 'La mise à jour MongoDB a échoué.');
    $context->users()->remove($ada); $expect($context->users()->count() === 2, 'La suppression MongoDB a échoué.');
});

$test("l'identifiant MongoDB explicite reste identique dans l'objet et le document", function () use ($expect): void {
    $context = new TestMongoContext(new MongoConnection(new MemoryMongoTransport(), 'app'));
    $document = new StringKeyDocument(); $document->id = 'manual-42'; $document->value = 'Manual';
    $context->stringKeys()->add($document);
    $stored = $context->stringKeys()->find('manual-42');
    $expect($document->id === 'manual-42' && $stored instanceof StringKeyDocument && $stored->id === 'manual-42', "L'identité MongoDB textuelle n'a pas été conservée.");
});

$test("objectId impose son contrat et convertit toutes les valeurs de in", function () use ($expect): void {
    $context = new TestMongoContext(new MongoConnection(new MemoryMongoTransport(), 'app'));
    $user = new MongoUser(); $user->name = 'Ada'; $user->age = 36; $context->users()->add($user);
    $found = $context->users()->where('_id', 'in', [$user->id])->all();
    $expect(count($found) === 1, 'Le filtre in doit accepter les identifiants objectId textuels valides.');
    try {
        $invalid = new MongoUser(); $invalid->id = 'manual-42'; $invalid->name = 'Invalid'; $invalid->age = 1;
        $context->users()->add($invalid);
        throw new RuntimeException('Un identifiant objectId invalide a été accepté.');
    } catch (InvalidArgumentException) {}
});

$test("le type d'identifiant MongoDB est déclaré par le modèle", function () use ($expect): void {
    $objectId = \AML\Data\MongoDB\Metadata\DocumentMetadata::from(MongoUser::class);
    $stringId = \AML\Data\MongoDB\Metadata\DocumentMetadata::from(StringKeyDocument::class);
    $expect($objectId->keyType === 'objectId' && $stringId->keyType === 'string', 'Le contrat du type d’identifiant doit être explicite.');
});

$test('les transactions MongoDB mémoire sont atomiques', function () use ($expect): void {
    $context = new TestMongoContext(new MongoConnection(new MemoryMongoTransport(), 'app'));
    try { $context->transaction(function (TestMongoContext $db): void { $user = new MongoUser(); $user->name = 'Rollback'; $user->age = 1; $db->users()->add($user); throw new RuntimeException('stop'); }); } catch (RuntimeException) {}
    $expect($context->users()->count() === 0, "La transaction MongoDB n'a pas été annulée.");
});

$test("un échec au démarrage d'une transaction libère la session", function () use ($expect, $officialTransport): void {
    $session = new FailingMongoSession();
    $transport = $officialTransport(new FakeMongoClient($session));
    foreach ([1, 2] as $_) {
        try { $transport->transaction(static fn (): null => null); }
        catch (RuntimeException $error) { $expect($error->getMessage() === 'start failed', "L'erreur de démarrage doit rester explicite."); }
    }
    $expect($session->starts === 2, "La première erreur ne doit pas empoisonner la session suivante.");
});

$test('le diagnostic distingue serveur autonome et replica set', function () use ($expect, $officialTransport): void {
    $standalone = $officialTransport(new FakeMongoClient(new FailingMongoSession(), false))->diagnostics();
    $replica = $officialTransport(new FakeMongoClient(new FailingMongoSession(), true))->diagnostics();
    $expect(($standalone['connected'] ?? false) === true && ($standalone['transactions'] ?? true) === false, 'Un serveur autonome ne doit pas annoncer les transactions.');
    $expect(($replica['transactions'] ?? false) === true, 'Un replica set doit annoncer les transactions.');
});

$test("l'adaptateur MongoDB s'enregistre dans ConnectionManager", function () use ($expect): void {
    $manager = new ConnectionManager('/tmp', ['default' => 'documents', 'connections' => ['documents' => ['driver' => 'mongodb', 'database' => 'app']]]);
    $manager->register('mongodb', new class implements DriverAdapter {
        public function connect(array $config, string $projectRoot): mixed { return new MongoConnection(new MemoryMongoTransport(), (string) $config['database']); }
    });
    $connection = $manager->connection();
    $expect($connection instanceof MongoConnection && $connection->database === 'app', "L'adaptateur enregistré n'est pas résolu.");
    $expect(($connection->transport->diagnostics()['connected'] ?? false) === true, 'Le diagnostic MongoDB est incorrect.');
});

$failed = 0;
foreach ($tests as $name => $case) { try { $case(); echo "✓ {$name}\n"; } catch (Throwable $error) { fwrite(STDERR, "✗ {$name}: {$error->getMessage()}\n"); $failed++; } }
exit($failed === 0 ? 0 : 1);
