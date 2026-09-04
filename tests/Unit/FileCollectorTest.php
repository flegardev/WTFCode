<?php

declare(strict_types=1);

namespace WTFCode\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WTFCode\Scanning\FileCollector;
use WTFCode\Scanning\RepositoryLimits;
use WTFCode\Scanning\SourceClassifier;

final class FileCollectorTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wtfcode-file-collector-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot . DIRECTORY_SEPARATOR . 'src', 0700, true);
        mkdir($this->fixtureRoot . DIRECTORY_SEPARATOR . 'node_modules', 0700, true);
        file_put_contents($this->fixtureRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'AuthService.php', "<?php\nfinal class AuthService {}\n");
        file_put_contents($this->fixtureRoot . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'ignored.js', 'export const ignored = true;');
        file_put_contents($this->fixtureRoot . DIRECTORY_SEPARATOR . 'binary.bin', "a\0b");
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->fixtureRoot)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->fixtureRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->fixtureRoot);
    }

    public function testItCollectsOnlyBoundedReadableSourceFiles(): void
    {
        $result = (new FileCollector())->collect($this->fixtureRoot);

        self::assertCount(1, $result['files']);
        self::assertSame('src/AuthService.php', $result['files'][0]['path']);
        self::assertSame('PHP', $result['files'][0]['language']);
        self::assertSame('authentication', $result['files'][0]['role']);
        self::assertSame(['AuthService'], $result['files'][0]['symbols']);
        self::assertSame([], $result['limitations']);
    }

    public function testItReportsConfiguredFileLimits(): void
    {
        file_put_contents($this->fixtureRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Second.php', "<?php\nfunction second() {}\n");
        $collector = new FileCollector(new SourceClassifier(), new RepositoryLimits(maxFiles: 1));
        $result = $collector->collect($this->fixtureRoot);

        self::assertCount(1, $result['files']);
        self::assertSame(['The scanner stopped after the 1-file limit.'], $result['limitations']);
    }
}
