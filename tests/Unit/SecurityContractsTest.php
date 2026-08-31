<?php

declare(strict_types=1);

namespace WTFCode\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityContractsTest extends TestCase
{
    public function testCsrfTokensRequireTwoEqualNonEmptyStrings(): void
    {
        self::assertTrue(\csrf_token_is_valid('known-token', 'known-token'));
        self::assertFalse(\csrf_token_is_valid('', ''));
        self::assertFalse(\csrf_token_is_valid('submitted', 'known'));
        self::assertFalse(\csrf_token_is_valid([], 'known'));
    }

    #[DataProvider('unsafeRepositoryUrls')]
    public function testRepositoryImporterRejectsUntrustedGithubUrls(string $url): void
    {
        self::assertNull(\RepositoryImporter::normalizeGithubUrl($url));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeRepositoryUrls(): iterable
    {
        yield 'plain HTTP' => ['http://github.com/example/project'];
        yield 'lookalike host' => ['https://github.com.example.test/example/project'];
        yield 'embedded credentials' => ['https://user:password@github.com/example/project'];
        yield 'query string' => ['https://github.com/example/project?token=secret'];
        yield 'fragment' => ['https://github.com/example/project#readme'];
        yield 'extra path component' => ['https://github.com/example/project/tree/main'];
    }

    public function testRepositoryImporterNormalizesAValidPublicRepository(): void
    {
        self::assertSame(
            'https://github.com/example/project.git',
            \RepositoryImporter::normalizeGithubUrl('https://github.com/example/project'),
        );
    }

    public function testSensitiveValuesAreRedactedFromDiagnosticText(): void
    {
        $databaseUrl = 'postgresql://user:password@example.test:5432/database';
        $message = \SensitiveDataSanitizer::text('Connection failed for ' . $databaseUrl);

        self::assertStringNotContainsString($databaseUrl, $message);
        self::assertStringContainsString('<redacted>', $message);
    }
}
