<?php

declare(strict_types=1);

namespace WTFCode\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EntitlementsTest extends TestCase
{
    public function testUnknownPlansCannotGrantMoreUsage(): void
    {
        self::assertSame(\Entitlements::limits('trial'), \Entitlements::limits('unlimited'));
        self::assertSame(1, \Entitlements::limits()['projects']);
        self::assertSame(5, \Entitlements::limits()['scans']);
        self::assertSame(0, \Entitlements::limits()['ai_calls']);
        self::assertSame('trial', \Entitlements::planForUser(123));
    }

    public function testProposedPlansRemainBounded(): void
    {
        self::assertSame(3, \Entitlements::limits('founder')['projects']);
        self::assertSame(20, \Entitlements::limits('founder')['scans']);
        self::assertSame(1, \Entitlements::limits('one-time')['scans']);
    }

    public function testPrivateAccessNeedsAnExplicitReleaseGateAsWellAsCredentials(): void
    {
        $credentials = array_fill_keys(['github_app_id', 'github_app_slug', 'github_app_client_id', 'github_app_client_secret', 'github_app_private_key'], 'test');
        $credentials['github_app_callback_url'] = 'https://example.test/callback';
        $credentials['environment'] = 'production';
        try {
            \GitHubAppService::configureForTests(static fn(): array => [], $credentials);
            self::assertFalse(\GitHubAppService::configured());
            $credentials['private_repositories_enabled'] = true;
            \GitHubAppService::configureForTests(static fn(): array => [], $credentials);
            self::assertTrue(\GitHubAppService::configured());
        } finally {
            \GitHubAppService::resetTests();
        }
    }
}
