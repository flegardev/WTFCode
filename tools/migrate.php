<?php

declare(strict_types=1);

define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';

if (!Database::isPostgres()) {
    fwrite(STDERR, "WTFCode production migrations require DB_DRIVER=pgsql or a PostgreSQL DATABASE_URL.\n");
    exit(1);
}

$pdo = Database::connection();
$schema = __DIR__ . '/../supabase/production-schema.sql';
$usersTable = $pdo->query("SELECT to_regclass('public.users')")->fetchColumn();
if ($usersTable === null) {
    $sql = file_get_contents($schema);
    if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('The production schema file is missing or empty.');
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $pdo->commit();
        fwrite(STDOUT, "Applied 202608200001_initial_production.\n");
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$applied = array_fill_keys($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN), true);
$migrations = glob(__DIR__ . '/../supabase/migrations/*.sql') ?: [];
sort($migrations, SORT_STRING);
foreach ($migrations as $migration) {
    $version = pathinfo($migration, PATHINFO_FILENAME);
    if (isset($applied[$version])) continue;
    $sql = file_get_contents($migration);
    if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Migration is empty: ' . basename($migration));
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $statement = $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (:version)');
        $statement->execute(['version' => $version]);
        $pdo->commit();
        fwrite(STDOUT, 'Applied ' . $version . ".\n");
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

fwrite(STDOUT, "Database migrations are current.\n");
