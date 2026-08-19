<?php

declare(strict_types=1);

final class SafeProcessRunner
{
    public function run(ProcessRunRequest $request): ProcessRunResult
    {
        $stdoutFile = tempnam(sys_get_temp_dir(), 'wtfcode-stdout-');
        $stderrFile = tempnam(sys_get_temp_dir(), 'wtfcode-stderr-');
        if ($stdoutFile === false || $stderrFile === false) {
            throw new RuntimeException('Unable to allocate isolated analyzer output files.');
        }
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];
        $started = hrtime(true);
        $process = @proc_open(
            $request->command,
            $descriptors,
            $pipes,
            $request->workingDirectory,
            $request->environment,
            ['bypass_shell' => true, 'suppress_errors' => true],
        );
        if (!is_resource($process)) {
            @unlink($stdoutFile);
            @unlink($stderrFile);
            throw new RuntimeException('Unable to start analyzer process.');
        }

        $stdout = '';
        $stderr = '';
        $stdoutTruncated = false;
        $stderrTruncated = false;
        $timedOut = false;
        $lastStatus = ['exitcode' => -1, 'running' => true];

        try {
            while (true) {
                clearstatcache(true, $stdoutFile);
                clearstatcache(true, $stderrFile);
                $stdoutTruncated = $stdoutTruncated || (int) @filesize($stdoutFile) > $request->stdoutLimitBytes;
                $stderrTruncated = $stderrTruncated || (int) @filesize($stderrFile) > $request->stderrLimitBytes;
                $lastStatus = proc_get_status($process);
                if (!$lastStatus['running']) break;
                if ($stdoutTruncated || $stderrTruncated) {
                    proc_terminate($process);
                    usleep(100_000);
                    $status = proc_get_status($process);
                    if ($status['running']) proc_terminate($process, 9);
                    break;
                }
                if ((hrtime(true) - $started) / 1_000_000_000 >= $request->timeoutSeconds) {
                    $timedOut = true;
                    proc_terminate($process);
                    usleep(100_000);
                    $status = proc_get_status($process);
                    if ($status['running']) proc_terminate($process, 9);
                    break;
                }
                usleep(10_000);
            }
        } finally {
            clearstatcache(true, $stdoutFile);
            clearstatcache(true, $stderrFile);
            $stdoutTruncated = $stdoutTruncated || (int) @filesize($stdoutFile) > $request->stdoutLimitBytes;
            $stderrTruncated = $stderrTruncated || (int) @filesize($stderrFile) > $request->stderrLimitBytes;
            $stdout = (string) file_get_contents($stdoutFile, false, null, 0, $request->stdoutLimitBytes);
            $stderr = (string) file_get_contents($stderrFile, false, null, 0, $request->stderrLimitBytes);
        }

        $reportedExit = (int) ($lastStatus['exitcode'] ?? -1);
        $closedExit = proc_close($process);
        @unlink($stdoutFile);
        @unlink($stderrFile);
        $exitCode = $reportedExit >= 0 ? $reportedExit : $closedExit;
        if ($timedOut) $exitCode = -1;
        return new ProcessRunResult(
            $request->command,
            $exitCode,
            $stdout,
            $stderr,
            (int) round((hrtime(true) - $started) / 1_000_000),
            $timedOut,
            $stdoutTruncated,
            $stderrTruncated,
        );
    }
}
