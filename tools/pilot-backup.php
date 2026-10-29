<?php

/** Encrypted logical backup, or restore into an EMPTY disposable loopback DB. */
declare(strict_types=1);
define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli' || !Database::isPostgres()) exit(2);
$mode = $argv[1] ?? ''; $path = $argv[2] ?? '';
if (!in_array($mode, ['backup', 'rehearse'], true) || $path === '') { fwrite(STDERR, "Usage: php tools/pilot-backup.php backup|rehearse /secure/path/backup.wtfb\n"); exit(2); }
$key = base64_decode((string) getenv('WTF_BACKUP_KEY'), true);
if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('Supply an external base64-encoded 32-byte WTF_BACKUP_KEY.');
$run = static function (array $command, array $connection): void {
    $environment = [];
    foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP'] as $name) { $value = getenv($name); if (is_string($value)) $environment[$name] = $value; }
    $environment += ['PGHOST' => $connection['host'], 'PGPORT' => (string) $connection['port'], 'PGUSER' => $connection['username'], 'PGPASSWORD' => $connection['password'], 'PGDATABASE' => $connection['database'], 'PGSSLMODE' => $connection['sslmode'], 'PGCONNECT_TIMEOUT' => '10'];
    // Credentials stay in the child environment, never arguments/output.
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('PostgreSQL utility unavailable.');
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('PostgreSQL backup/restore utility failed. Check private operator logs and utility versions.');
};
$config = app_config();
$temporary = tempnam(sys_get_temp_dir(), 'wtf-backup-');
if ($temporary === false) throw new RuntimeException('Secure temporary storage unavailable.');
chmod($temporary, 0600);
try {
    if ($mode === 'backup') {
        $parent = realpath(dirname($path)); $public = realpath(__DIR__ . '/../public');
        if (!$parent || str_starts_with(strtolower($parent . DIRECTORY_SEPARATOR), strtolower($public . DIRECTORY_SEPARATOR))) throw new RuntimeException('Backup destination must be outside the public directory.');
        $run(['pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--file=' . $temporary], $config);
        if (filesize($temporary) > 67108864) throw new RuntimeException('Backup exceeds the 64 MiB pilot tool limit. Use an approved streaming encrypted backup process.');
        $nonce = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt((string) file_get_contents($temporary), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'WTFB1', 16);
        if ($cipher === false) throw new RuntimeException('Backup encryption failed.');
        $handle = fopen($path, 'x'); if ($handle === false) throw new RuntimeException('Backup exists or destination is unavailable.');
        chmod($path, 0600); $bytes = 'WTFB1' . $nonce . $tag . $cipher;
        if (fwrite($handle, $bytes) !== strlen($bytes)) { fclose($handle); throw new RuntimeException('Backup write incomplete.'); } fclose($handle);
        echo json_encode(['backup' => 'encrypted', 'encrypted_bytes' => strlen($bytes), 'created_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        $target = parse_url((string) getenv('WTF_RESTORE_DATABASE_URL'));
        if (!is_array($target) || !in_array($target['scheme'] ?? '', ['postgres', 'postgresql'], true) || !in_array($target['host'] ?? '', ['127.0.0.1', 'localhost'], true) || !in_array($config['environment'], ['local', 'testing'], true)) throw new RuntimeException('Rehearsal requires a disposable loopback target and local/testing environment.');
        $db = rawurldecode(ltrim($target['path'] ?? '', '/'));
        if ($db === '' || $db === $config['database']) throw new RuntimeException('Restore target must be a distinct empty database.');
        $restore = ['host' => $target['host'], 'port' => (string) ($target['port'] ?? 5432), 'username' => rawurldecode($target['user'] ?? ''), 'password' => rawurldecode($target['pass'] ?? ''), 'database' => $db, 'sslmode' => 'disable'];
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $db)) throw new RuntimeException('Invalid rehearsal database name.');
        $pdo = new PDO('pgsql:host=' . $restore['host'] . ';port=' . $restore['port'] . ';dbname=' . $db . ';sslmode=disable', $restore['username'], $restore['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ((int) $pdo->query("SELECT COUNT(*) FROM pg_tables WHERE schemaname = 'public'")->fetchColumn() !== 0) throw new RuntimeException('Restore target is not empty. No tables were overwritten.');
        if (!is_file($path) || filesize($path) > 67108900) throw new RuntimeException('Backup absent or over pilot size limit.');
        $bytes = (string) file_get_contents($path);
        if (substr($bytes, 0, 5) !== 'WTFB1') throw new RuntimeException('Unknown backup format.');
        $plain = openssl_decrypt(substr($bytes, 33), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes, 5, 12), substr($bytes, 17, 16), 'WTFB1');
        if ($plain === false) throw new RuntimeException('Backup authentication failed.');
        file_put_contents($temporary, $plain); unset($plain);
        $run(['pg_restore', '--exit-on-error', '--no-owner', '--no-privileges', '--dbname=' . $db, $temporary], $restore);
        $counts = [];
        foreach (['users', 'projects', 'scan_runs', 'project_files', 'schema_migrations'] as $table) $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        echo json_encode(['restore' => 'completed', 'target' => $db, 'counts' => $counts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} finally { if (is_file($temporary)) unlink($temporary); }
