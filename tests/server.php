<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AML\Data\MongoDB\MongoDriverAdapter;
use AML\Data\Entity;
use AML\Data\MongoDB\Metadata\{Collection, DocumentId};
use AML\Data\MongoDB\MongoContext;

#[Collection('phpaml_cycle_test')]
final class ServerCycleDocument extends Entity
{
    #[DocumentId] public string $id;
    public string $value;
}

final class ServerCycleContext extends MongoContext
{
    /** @return \AML\Data\MongoDB\MongoSet<ServerCycleDocument> */
    public function documents(): \AML\Data\MongoDB\MongoSet { return $this->set(ServerCycleDocument::class); }
}

$uri = getenv('AML_DATA_MONGODB_URI');
$database = getenv('AML_DATA_MONGODB_DATABASE') ?: 'phpaml_data_test';
if (!is_string($uri) || $uri === '') { echo "↷ MongoDB ignoré : AML_DATA_MONGODB_URI absent.\n"; exit(0); }
try {
    $connection = (new MongoDriverAdapter())->connect(['uri' => $uri, 'database' => $database], getcwd() ?: '.');
    $report = $connection->transport->diagnostics();
    if (($report['connected'] ?? false) !== true) throw new RuntimeException((string) ($report['error'] ?? 'Connexion impossible.'));
    $context = new ServerCycleContext($connection);
    $document = new ServerCycleDocument(); $document->value = 'created';
    $context->documents()->add($document);
    if (!isset($document->id) || preg_match('/^[a-f0-9]{24}$/i', $document->id) !== 1) throw new RuntimeException("L'ObjectId inséré n'a pas été normalisé en chaîne.");
    $stored = $context->documents()->find($document->id);
    if (!$stored instanceof ServerCycleDocument) throw new RuntimeException("Le document inséré n'a pas été retrouvé par son identifiant textuel.");
    $inResult = $context->documents()->where('_id', 'in', [$document->id])->first();
    if (!$inResult instanceof ServerCycleDocument) throw new RuntimeException("Le filtre in n'a pas converti l'identifiant textuel en ObjectId.");
    $stored->value = 'updated'; $context->documents()->update($stored);
    if ($context->documents()->find($document->id)?->value !== 'updated') throw new RuntimeException("Le document n'a pas été mis à jour.");
    $context->documents()->remove($stored);
    if ($context->documents()->find($document->id) !== null) throw new RuntimeException("Le document n'a pas été supprimé.");
    $committed = $connection->transaction(function () use ($context): ServerCycleDocument {
        $item = new ServerCycleDocument(); $item->value = 'committed';
        $context->documents()->add($item);
        return $item;
    });
    if (!$context->documents()->find($committed->id) instanceof ServerCycleDocument) throw new RuntimeException("La transaction validée n'a pas conservé le document.");
    $rolledBackId = null;
    try {
        $connection->transaction(function () use ($context, &$rolledBackId): void {
            $item = new ServerCycleDocument(); $item->value = 'rolled-back';
            $context->documents()->add($item); $rolledBackId = $item->id;
            throw new RuntimeException('rollback-test');
        });
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'rollback-test') throw $error;
    }
    if (!is_string($rolledBackId) || $context->documents()->find($rolledBackId) !== null) throw new RuntimeException("La transaction interrompue n'a pas annulé l'insertion.");
    $context->documents()->remove($committed);
    try {
        $connection->transaction(fn () => $connection->transaction(static fn (): null => null));
        throw new RuntimeException('Une transaction MongoDB imbriquée a été acceptée.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'imbriquées')) throw $error;
    }
    echo "✓ MongoDB connecté et cycle ObjectId CRUD validé.\n";
} catch (Throwable $error) { fwrite(STDERR, '✗ MongoDB: ' . $error->getMessage() . "\n"); exit(1); }
