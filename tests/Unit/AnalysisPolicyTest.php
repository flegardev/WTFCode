<?php

declare(strict_types=1);

namespace WTFCode\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AnalysisPolicyTest extends TestCase
{
    public function testUnknownProfilesFallBackToQuick(): void
    {
        self::assertSame(\AnalysisProfile::QUICK, \AnalysisProfile::normalize('not-a-profile'));
        self::assertSame(\AnalysisProfile::DEEP, \AnalysisProfile::normalize(' DEEP '));
    }

    public function testQuickAnalysisDoesNotSilentlyEnableSecurityBinaries(): void
    {
        self::assertFalse(\AnalysisProfile::includes(\AnalysisProfile::QUICK, 'gitleaks'));
        self::assertTrue(\AnalysisProfile::includes(\AnalysisProfile::SECURITY, 'gitleaks'));
        self::assertTrue(\AnalysisProfile::includes(\AnalysisProfile::MAXIMUM, 'gitleaks'));
    }

    public function testFixturesAndDocumentationAreNotRuntimeEvidence(): void
    {
        self::assertFalse(\RuntimeEvidencePolicy::isRuntimePath('tests/fixtures/AuthService.php'));
        self::assertFalse(\RuntimeEvidencePolicy::isRuntimePath('docs/security.md'));
        self::assertTrue(\RuntimeEvidencePolicy::isRuntimePath('app/Http/AuthController.php'));
    }
}
