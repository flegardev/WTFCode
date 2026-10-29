<?php

declare(strict_types=1);
define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli' || !Database::isPostgres()) exit(2);
$pdo = Database::connection();
$version = '20261028172900_pilot_account_lifecycle';
$pdo->beginTransaction();
try {
    $pdo->query("SELECT pg_advisory_xact_lock(hashtextextended('pilot-schema-upgrade', 0))");
    if (!$pdo->query("SELECT to_regclass('public.users')")->fetchColumn()) {
        $pdo->exec((string) file_get_contents(__DIR__ . '/../supabase/production-schema.sql'));
    }
    $q = $pdo->prepare('SELECT version FROM schema_migrations WHERE version = ?'); $q->execute([$version]);
    if (!$q->fetchColumn()) {
        $pdo->exec((string) file_get_contents(__DIR__ . '/../supabase/migrations/' . $version . '.sql'));
        $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute([$version]);
    }
    $pdo->commit();
    echo "Pilot schema is current. Historical migrations were not replayed.\n";
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
