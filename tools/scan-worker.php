<?php

declare(strict_types=1);

use WTFCode\Application\ScanJobRunner;

define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

@set_time_limit(0);
$arguments = array_slice($argv, 1);
$once = in_array('--once', $arguments, true);
$loop = in_array('--loop', $arguments, true);
if ($once === $loop) {
    fwrite(STDERR, "Usage: php tools/scan-worker.php --once|--loop [--poll=5]\n");
    exit(2);
}

$pollSeconds = 5;
foreach ($arguments as $argument) {
    if (preg_match('/^--poll=(\d+)$/', $argument, $matches) === 1) {
        $pollSeconds = max(1, min(60, (int) $matches[1]));
    }
}

$host = preg_replace('/[^A-Za-z0-9_.-]/', '-', (string) (gethostname() ?: 'host')) ?: 'host';
$workerId = substr($host . ':' . getmypid() . ':' . bin2hex(random_bytes(4)), 0, 100);
$runner = new ScanJobRunner();
$isDatabaseFailure = static function (Throwable $exception): bool {
    $current = $exception;
    do {
        if ($current instanceof PDOException) {
            return true;
        }
        $current = $current->getPrevious();
    } while ($current instanceof Throwable);

    return false;
};

do {
    try {
        $job = AnalysisJobStore::claimNext($workerId);
        if ($job === null) {
            if ($once) {
                fwrite(STDOUT, "No scan job is available.\n");
                exit(0);
            }
            sleep($pollSeconds);
            continue;
        }

        $jobId = (int) $job['id'];
        fwrite(STDOUT, sprintf("Claimed scan job %d.%s", $jobId, PHP_EOL));
        $result = $runner->run($job);
        fwrite(STDOUT, sprintf("Scan job %d ended in state %s.%s", $result['job_id'], $result['state'], PHP_EOL));
        if ($once && !in_array($result['state'], ['completed', 'partial'], true)) {
            exit(1);
        }
    } catch (Throwable $exception) {
        $databaseFailure = $isDatabaseFailure($exception);
        Logger::error('Scan worker loop failed', [
            'worker_id' => $workerId,
            'type' => get_class($exception),
            'database_failure' => $databaseFailure,
        ]);

        if ($databaseFailure) {
            Database::disconnect();
            fwrite(STDERR, "The scan worker lost its database connection and stopped. A supervisor can restart it safely.\n");
            exit(1);
        }

        fwrite(STDERR, "The scan worker encountered an internal error. Details were written to the application log.\n");
        if ($once) {
            exit(1);
        }
        sleep($pollSeconds);
    }
} while ($loop);
