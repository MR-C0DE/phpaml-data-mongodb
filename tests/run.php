<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AML\Data\Connections\ConnectionManager;
use AML\Data\Connections\DriverAdapter;
use AML\Data\Entity;
use AML\Data\MongoDB\Metadata\{Collection, DocumentId};
use AML\Data\MongoDB\{MongoConnection, MongoContext};
use AML\Data\MongoDB\Testing\MemoryMongoTransport;

#[Collection('users')]
final class MongoUser extends Entity
{
    #[DocumentId] public string $id;
    public string $name;
    public int $age;
}

final class TestMongoContext extends MongoContext
{
    /** @return \AML\Data\MongoDB\MongoSet<MongoUser> */
    public function users(): \AML\Data\MongoDB\MongoSet { return $this->set(MongoUser::class); }
}

$tests = [];
$test = static function (string $name, Closure $case) use (&$tests): void { $tests[$name] = $case; };
$expect = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$test('MongoSet fournit CRUD, requêtes et pagination', function () use ($expect): void {
    $context = new TestMongoContext(new MongoConnection(new MemoryMongoTransport(), 'app'));
    foreach ([['Ada', 36], ['Grace', 44], ['Linus', 28]] as [$name, $age]) { $user = new MongoUser(); $user->name = $name; $user->age = $age; $context->users()->add($user); }
    $page = $context->users()->where('age', '>=', 30)->orderBy('age', 'desc')->paginate(1, 2);
    $expect($page->total === 2 && $page->items[0]->name === 'Grace', 'La requête MongoDB est incorrecte.');
    $ada = $context->users()->find('1'); $expect($ada instanceof MongoUser && $ada->name === 'Ada', "L'hydratation MongoDB a échoué.");
    $ada->name = 'Ada Lovelace'; $context->users()->update($ada);
    $expect($context->users()->find('1')?->name === 'Ada Lovelace', 'La mise à jour MongoDB a échoué.');
    $context->users()->remove($ada); $expect($context->users()->count() === 2, 'La suppression MongoDB a échoué.');
});

$test('les transactions MongoDB mémoire sont atomiques', function () use ($expect): void {
    $context = new TestMongoContext(new MongoConnection(new MemoryMongoTransport(), 'app'));
    try { $context->transaction(function (TestMongoContext $db): void { $user = new MongoUser(); $user->name = 'Rollback'; $user->age = 1; $db->users()->add($user); throw new RuntimeException('stop'); }); } catch (RuntimeException) {}
    $expect($context->users()->count() === 0, "La transaction MongoDB n'a pas été annulée.");
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
