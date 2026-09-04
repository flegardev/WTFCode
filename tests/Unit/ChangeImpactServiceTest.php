<?php

declare(strict_types=1);

namespace WTFCode\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WTFCode\Application\ChangeImpactService;
use WTFCode\Application\ChangeTargetResolver;

final class ChangeImpactServiceTest extends TestCase
{
    public function testNoObservedEvidenceProducesUnknownRisk(): void
    {
        $risk = ChangeImpactService::classify([], [], [], [], []);

        self::assertSame('unknown', $risk['level']);
        self::assertStringContainsString('Runtime behavior', $risk['reasons'][0]);
    }

    public function testOneOrdinaryFileIsLowRiskInsteadOfBeingSilentlyEscalated(): void
    {
        $risk = ChangeImpactService::classify(['src/Formatting/Slug.php'], [], [], [], []);

        self::assertSame('low', $risk['level']);
    }

    #[DataProvider('sensitivePaths')]
    public function testSensitivePathsAreHighRiskAcrossCommonNamingStyles(string $path): void
    {
        $risk = ChangeImpactService::classify([$path], [], [], [], []);

        self::assertSame('high', $risk['level']);
        self::assertSame('1 sensitive path changed or affected', $risk['reasons'][0]);
    }

    /** @return iterable<string, array{string}> */
    public static function sensitivePaths(): iterable
    {
        yield 'directory' => ['src/Auth/LoginController.php'];
        yield 'camel case filename' => ['src/Http/SessionMiddleware.php'];
        yield 'migration' => ['database/migrations/008_add_accounts.sql'];
        yield 'environment file' => ['.env.production'];
        yield 'windows path' => ['config\\database.php'];
    }

    public function testDataAndServiceBoundariesRemainHighRisk(): void
    {
        $risk = ChangeImpactService::classify(
            ['src/Billing/Invoice.php'],
            [],
            [['name' => 'invoices']],
            [['name' => 'Stripe']],
            [],
        );

        self::assertSame('high', $risk['level']);
        self::assertContains('1 data boundary affected', $risk['reasons']);
        self::assertContains('1 external service boundary affected', $risk['reasons']);
    }

    public function testFiveAffectedRoutesEscalateToHighRisk(): void
    {
        $routes = array_map(
            static fn(int $index): array => ['route_path' => '/route-' . $index],
            range(1, 5),
        );

        $risk = ChangeImpactService::classify(['src/Http/Routes.php'], $routes, [], [], []);

        self::assertSame('high', $risk['level']);
        self::assertContains('5 routes affected', $risk['reasons']);
    }

    public function testMediumBlastRadiusProducesMediumRisk(): void
    {
        $risk = ChangeImpactService::classify(['src/Domain/Formatter.php'], [], [], [], ['medium']);

        self::assertSame('medium', $risk['level']);
    }

    public function testRouteTargetSeparatesMethodFromPath(): void
    {
        self::assertSame(['POST', '/accounts/{id}'], ChangeTargetResolver::splitRouteTarget('post /accounts/{id}'));
        self::assertSame([null, '/accounts/{id}'], ChangeTargetResolver::splitRouteTarget('/accounts/{id}'));
    }

    public function testTypedTargetResolutionPrefersExactIdentityOverFuzzyMatches(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'AccountArchive', 'qualified_name' => 'App\\AccountArchive', 'path' => 'src/AccountArchive.php'],
            ['id' => 2, 'name' => 'Account', 'qualified_name' => 'App\\Account', 'path' => 'src/Account.php'],
        ];

        self::assertSame([2], array_column(ChangeTargetResolver::preferExactRows($rows, 'Account'), 'id'));
        self::assertSame([1], array_column(ChangeTargetResolver::preferExactRows($rows, 'Archive'), 'id'));
        self::assertSame([], ChangeTargetResolver::preferExactRows($rows, 'Missing'));
    }

    public function testBlastRadiusExternalServicesAreMergedAndDeduplicated(): void
    {
        $services = ChangeImpactService::mergeBoundaryRows(
            ['41' => ['id' => 41, 'name' => 'Stripe']],
            [
                ['id' => 41, 'name' => 'Stripe API'],
                ['id' => 52, 'name' => 'Mailgun'],
            ],
        );

        self::assertCount(2, $services);
        self::assertSame('Stripe API', $services['41']['name']);
        self::assertSame('Mailgun', $services['52']['name']);
    }
}
