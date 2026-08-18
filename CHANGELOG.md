# Changelog

## 0.1.0-alpha.3 — 2026-08-17

- contrat explicite des identifiants `objectId` et `string` ;
- conversion des ObjectId dans les filtres simples et `in` ;
- transactions imbriquées refusées proprement et sessions toujours libérées ;
- diagnostic de compatibilité transactionnelle fondé sur la topologie MongoDB ;
- CI préparée pour le cycle CRUD et les transactions sur replica set.

## 0.1.0-alpha.2 — 2026-08-17

- correction du chemin d'analyse statique pour une installation autonome;
- validation CI sur PHP 8.2, 8.3 et 8.4.

## 0.1.0-alpha.1 — 2026-08-17

- adaptateur découvrable par `ConnectionManager`;
- documents typés, CRUD et requêtes chaînables;
- pagination et transactions par session;
- transport officiel et transport mémoire;
- diagnostic et validation contre MongoDB 8.2 en replica set;
- PHPStan au niveau maximal.
