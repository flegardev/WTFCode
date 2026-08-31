<?php

declare(strict_types=1);

define('WTF_CODE_TESTING', true);
define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';

function github_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$privateKey = 'test-private-key-not-used-by-mock-transport';
$issued = [];
$revoked = [];
$authorizedUser = true;
$revokedInstallation = false;
$transport = static function (string $method, string $url, ?string $bearer, ?array $body) use (&$issued, &$revoked, &$authorizedUser, &$revokedInstallation): array {
    if ($method === 'POST' && str_ends_with($url, '/login/oauth/access_token')) return ['access_token' => 'ghu_test_user_authorization_1234567890'];
    if ($method === 'GET' && str_contains($url, '/user/installations')) return ['installations' => $authorizedUser ? [['id' => 778899]] : []];
    if ($method === 'GET' && str_ends_with($url, '/app/installations/778899')) return ['id' => 778899, 'account' => ['id' => 112233, 'login' => 'example', 'type' => 'User'], 'repository_selection' => 'selected', 'permissions' => ['contents' => 'read'], 'suspended_at' => null];
    if ($method === 'POST' && str_contains($url, '/access_tokens')) {
        if ($revokedInstallation) throw new GitHubAccessException('not found', 'mock revoked installation', 'not_found');
        $token = 'ghs_test_' . bin2hex(random_bytes(16));
        $issued[] = $token;
        github_assert(($body['repository_ids'] ?? null) === [445566], 'Private operation token must be repository-ID scoped.');
        return ['token' => $token, 'expires_at' => gmdate(DATE_ATOM, time() + 3600)];
    }
    if ($method === 'DELETE' && str_ends_with($url, '/installation/token')) {
        $revoked[] = (string) $bearer;
        return [];
    }
    throw new RuntimeException('Unexpected GitHub test request: ' . $method . ' ' . $url);
};

GitHubAppService::configureForTests($transport, [
    'environment' => 'test',
    'github_app_id' => '12345',
    'github_app_slug' => 'wtfcode-test',
    'github_app_client_id' => 'Iv1.test',
    'github_app_client_secret' => 'test-secret',
    'github_app_private_key' => $privateKey,
    'github_app_callback_url' => 'https://example.test/github-callback.php',
]);

$installation = GitHubAppService::completeInstallation('valid-code', 778899);
github_assert((int) $installation['id'] === 778899, 'Authorized GitHub users should link an accessible installation.');
$authorizedUser = false;
try {
    GitHubAppService::completeInstallation('valid-code', 778899);
    throw new RuntimeException('Installation hijacking check unexpectedly passed.');
} catch (GitHubAccessException $exception) {
    github_assert($exception->reason === 'installation_not_authorized', 'The callback must reject installations absent from the authorizing GitHub user.');
}
$authorizedUser = true;

$tokenMethod = new ReflectionMethod(GitHubAppService::class, 'withInstallationToken');
$first = $tokenMethod->invoke(null, 778899, [445566], static fn (string $token): string => $token);
$second = $tokenMethod->invoke(null, 778899, [445566], static fn (string $token): string => $token);
github_assert($first !== $second, 'Every private repository operation must mint a fresh installation token.');
github_assert($revoked === [$first, $second], 'Installation tokens must be revoked after each operation.');
$revokedInstallation = true;
try {
    $tokenMethod->invoke(null, 778899, [445566], static fn (): null => null);
    throw new RuntimeException('Revoked installation unexpectedly issued a token.');
} catch (GitHubAccessException $exception) {
    github_assert($exception->reason === 'authorization_revoked', 'Revoked GitHub authorization should request reconnection.');
    github_assert(!str_contains($exception->safeMessage(), 'ghs_'), 'Safe authorization errors must never contain tokens.');
}

GitHubAppService::resetTests();
fwrite(STDOUT, "GitHub App state, installation ownership proof, token scope, freshness, revocation, and safe-error checks passed.\n");
