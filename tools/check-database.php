<?php

declare(strict_types=1);

define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';

$requiredTables = [
    'users', 'projects', 'project_files', 'project_dependencies', 'architecture_nodes', 'architecture_edges',
    'scan_runs', 'scan_findings', 'analysis_provider_runs', 'package_inventory', 'code_symbols',
    'symbol_relationships', 'code_routes', 'learning_progress', 'change_guard_snapshots', 'analysis_jobs',
    'analysis_job_steps', 'engine_disagreements', 'sessions', 'login_attempts', 'provider_cache_entries', 'schema_migrations',
];

try {
    $pdo = Database::connection();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') throw new RuntimeException('The active PDO driver is not pgsql.');
    $statement = $pdo->prepare("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public' AND tablename = ANY(string_to_array(:tables, ','))");
    $statement->execute(['tables' => implode(',', $requiredTables)]);
    $existing = $statement->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($requiredTables, $existing));
    $migration = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
    $migration->execute(['version' => '202608200001_initial_production']);
    if ($missing !== [] || !$migration->fetchColumn()) throw new RuntimeException('Required schema objects or migration records are missing.');
    fwrite(STDOUT, 'PostgreSQL connection, required tables, and migrations are ready.' . PHP_EOL);
} catch (Throwable) {
    fwrite(STDERR, 'Database readiness check failed. No credentials were printed.' . PHP_EOL);
    exit(1);
}
