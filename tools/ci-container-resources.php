<?php

declare(strict_types=1);
define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli' || getenv('WTF_DISPOSABLE_CONTAINER_TEST') !== 'true' || app_config()['host'] !== 'database' || app_config()['database'] !== 'wtfcode' || app_config()['storage_path'] !== '/tmp/wtfcode') throw new RuntimeException('Disposable Compose worker only.');
$pdo = Database::connection();
if ((int) $pdo->query('SELECT COUNT(*) FROM analysis_jobs')->fetchColumn() !== 0) throw new RuntimeException('Resource probe requires an idle disposable queue.');
if ((glob('/tmp/wtfcode/repos/project-*') ?: []) !== []) throw new RuntimeException('A production checkout survived worker cleanup.');
$read = static fn (string $path): ?string => is_readable($path) ? trim((string) file_get_contents($path)) : null;
$before = ['memory_limit' => $read('/sys/fs/cgroup/memory.max'), 'memory_peak_before_disk_probe' => $read('/sys/fs/cgroup/memory.peak'), 'cpu_max' => $read('/sys/fs/cgroup/cpu.max'), 'pids_max' => $read('/sys/fs/cgroup/pids.max')];
if ($before['memory_limit'] !== '2147483648' || $before['pids_max'] !== '128') throw new RuntimeException('Worker cgroup limits differ from the pilot configuration.');
$path = '/tmp/wtfcode/tmp/cap-probe-' . bin2hex(random_bytes(8));
$handle = fopen($path, 'x');
if ($handle === false) throw new RuntimeException('Probe file could not be opened.');
$written = 0;
$full = false;
try {
    $chunk = str_repeat('x', 1048576);
    for ($i = 0; $i < 540; $i++) {
        $bytes = @fwrite($handle, $chunk);
        if ($bytes === false || $bytes < strlen($chunk)) { $written += $bytes === false ? 0 : $bytes; $full = true; break; }
        $written += $bytes;
    }
} finally {
    fclose($handle);
    unlink($path);
}
if (!$full || $written > 536870912 || disk_free_space('/tmp/wtfcode') < 500000000) throw new RuntimeException('The hard temporary storage cap or cleanup was not verified.');
echo json_encode(['status' => 'passed', 'production_checkout_cleanup' => true, 'cgroup' => $before, 'tmpfs_enospc_observed' => $full, 'probe_bytes_written' => $written, 'probe_removed' => !file_exists($path), 'memory_peak_after_disk_probe' => $read('/sys/fs/cgroup/memory.peak')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

