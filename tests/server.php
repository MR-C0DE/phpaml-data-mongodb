<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AML\Data\MongoDB\MongoDriverAdapter;

$uri = getenv('AML_DATA_MONGODB_URI');
$database = getenv('AML_DATA_MONGODB_DATABASE') ?: 'phpaml_data_test';
if (!is_string($uri) || $uri === '') { echo "↷ MongoDB ignoré : AML_DATA_MONGODB_URI absent.\n"; exit(0); }
try {
    $connection = (new MongoDriverAdapter())->connect(['uri' => $uri, 'database' => $database], getcwd() ?: '.');
    $report = $connection->transport->diagnostics();
    if (($report['connected'] ?? false) !== true) throw new RuntimeException((string) ($report['error'] ?? 'Connexion impossible.'));
    echo "✓ MongoDB connecté.\n";
} catch (Throwable $error) { fwrite(STDERR, '✗ MongoDB: ' . $error->getMessage() . "\n"); exit(1); }
