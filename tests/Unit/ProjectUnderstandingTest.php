<?php

declare(strict_types=1);

namespace WTFCode\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WTFCode\Application\ProjectUnderstanding;

final class ProjectUnderstandingTest extends TestCase
{
    public function testNoEvidenceDoesNotInventABusinessPurpose(): void
    {
        self::assertStringContainsString('not enough supported source evidence', ProjectUnderstanding::summary([], [], []));
    }

    public function testOnlyStoredRoutesAndTechnologiesSupportTheApiSummary(): void
    {
        $summary = ProjectUnderstanding::summary([], [['route_path' => '/articles'], ['route_path' => '/articles']], ['Express']);
        self::assertStringContainsString('appears to provide a web API', $summary);
        self::assertStringNotContainsString('/articles', $summary);
        self::assertStringContainsString('Express', $summary);
        self::assertStringNotContainsString('payments', $summary);
    }

    public function testSystemDetectionAloneDoesNotInventHttpRoutes(): void
    {
        $summary = ProjectUnderstanding::summary([['label' => 'Docker environment']], [], []);
        self::assertStringContainsString('Docker environment', $summary);
        self::assertStringNotContainsString('web API', $summary);
    }
}
