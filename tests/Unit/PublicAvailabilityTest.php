<?php

declare(strict_types=1);

namespace WTFCode\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PublicAvailabilityTest extends TestCase
{
    public function testAnonymousLandingSurvivesDatabaseOutageWhilePrivateRoutesFailClosed(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($address);
        $environment = getenv();
        unset($environment['DATABASE_URL']);
        $environment = array_merge($environment, [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
            'APP_URL' => 'http://' . $address, 'SESSION_DRIVER' => 'database',
            'DB_DRIVER' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1',
        ]);
        $log = tempnam(sys_get_temp_dir(), 'wtf-availability-');
        self::assertIsString($log);
        $process = proc_open(
            [PHP_BINARY, '-S', $address, '-t', 'public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $body = false;
            for ($attempt = 0; $attempt < 50 && $body === false; $attempt++) {
                usleep(100000);
                $body = @file_get_contents('http://' . $address . '/index.php');
            }
            self::assertIsString($body);
            self::assertStringContainsString('Understand unfamiliar code', $body);
            self::assertStringContainsString('200', $http_response_header[0]);
            $context = stream_context_create(['http' => ['ignore_errors' => true]]);
            $health = file_get_contents('http://' . $address . '/health.php', false, $context);
            self::assertStringContainsString('503', $http_response_header[0]);
            self::assertIsString($health);
            self::assertStringContainsString('unavailable', $health);
            $private = file_get_contents('http://' . $address . '/dashboard.php', false, $context);
            self::assertStringContainsString('500', $http_response_header[0]);
            self::assertIsString($private);
            self::assertStringNotContainsString('password', $private);
            $cookieContext = stream_context_create(['http' => ['ignore_errors' => true, 'header' => 'Cookie: wtfcode_session=existing-session']]);
            file_get_contents('http://' . $address . '/index.php', false, $cookieContext);
            self::assertStringContainsString('500', $http_response_header[0]);
        } finally {
            proc_terminate($process);
            proc_close($process);
            unlink($log);
        }
    }
}
