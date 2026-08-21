<?php

declare(strict_types=1);

final class GitHubAppService
{
    private const API = 'https://api.github.com';
    private const OAUTH_TOKEN_URL = 'https://github.com/login/oauth/access_token';
    private const API_VERSION = '2022-11-28';
    /** @var null|Closure(string,string,?string,?array):array<string,mixed> */
    private static ?Closure $testTransport = null;
    /** @var null|array<string,mixed> */
    private static ?array $testConfiguration = null;

    /** @param Closure(string,string,?string,?array):array<string,mixed> $transport @param array<string,mixed> $configuration */
    public static function configureForTests(Closure $transport, array $configuration): void
    {
        if (!defined('WTF_CODE_TESTING') || WTF_CODE_TESTING !== true) throw new LogicException('GitHub test transport is unavailable outside tests.');
        self::$testTransport = $transport;
        self::$testConfiguration = $configuration;
    }

    public static function resetTests(): void
    {
        self::$testTransport = null;
        self::$testConfiguration = null;
    }

    public static function configured(): bool
    {
        $config = self::configuration();
        foreach (['github_app_id', 'github_app_slug', 'github_app_client_id', 'github_app_client_secret', 'github_app_private_key', 'github_app_callback_url'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') return false;
        }
        $callback = (string) $config['github_app_callback_url'];
        if (filter_var($callback, FILTER_VALIDATE_URL) === false) return false;
        return ($config['environment'] ?? 'local') !== 'production' || strtolower((string) parse_url($callback, PHP_URL_SCHEME)) === 'https';
    }

    public static function installationUrl(string $state): string
    {
        self::requireConfigured();
        return 'https://github.com/apps/' . rawurlencode((string) self::configuration()['github_app_slug']) . '/installations/new?state=' . rawurlencode($state);
    }

    /** @return array<string, mixed>|null Null means GitHub API classification was temporarily unavailable. */
    public static function inspectPublicRepository(string $repositoryUrl): ?array
    {
        $normalized = RepositoryImporter::normalizeGithubUrl($repositoryUrl);
        if ($normalized === null) return null;
        $path = preg_replace('/\.git$/', '', trim((string) parse_url($normalized, PHP_URL_PATH), '/')) ?? '';
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $path)) return null;
        try {
            $repository = self::request('GET', self::API . '/repos/' . $path);
        } catch (GitHubAccessException $exception) {
            if ($exception->reason === 'not_found') {
                throw new GitHubAccessException('Repository not found or private. Check the URL, or connect GitHub and choose it from the repository picker.', $exception->getMessage(), 'repository_unavailable', $exception);
            }
            return null;
        }
        $result = self::normalizeRepository($repository);
        if ($result !== null && (bool) $result['private']) {
            throw new GitHubAccessException('Private repository detected. Connect GitHub and select it from the repository picker.', 'Unauthenticated API unexpectedly reported a private repository.', 'private_repository');
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public static function completeInstallation(string $code, int $installationId): array
    {
        if ($code === '' || $installationId < 1) {
            throw new GitHubAccessException('GitHub did not complete authorization. Reconnect and approve the requested repository access.', 'OAuth callback missing code or installation id.', 'authorization_incomplete');
        }
        self::requireConfigured();
        $userToken = null;
        try {
            $response = self::request('POST', self::OAUTH_TOKEN_URL, null, [
                'client_id' => (string) self::configuration()['github_app_client_id'],
                'client_secret' => (string) self::configuration()['github_app_client_secret'],
                'code' => $code,
                'redirect_uri' => (string) self::configuration()['github_app_callback_url'],
            ]);
            $userToken = (string) ($response['access_token'] ?? '');
            if ($userToken === '') throw new GitHubAccessException('GitHub authorization could not be verified. Please reconnect.', 'OAuth token exchange returned no access token.', 'oauth_exchange_failed');

            $verified = false;
            for ($page = 1; $page <= 10 && !$verified; $page++) {
                $installations = self::request('GET', self::API . '/user/installations?per_page=100&page=' . $page, $userToken);
                $items = is_array($installations['installations'] ?? null) ? $installations['installations'] : [];
                foreach ($items as $item) {
                    if ((int) ($item['id'] ?? 0) === $installationId) { $verified = true; break; }
                }
                if (count($items) < 100) break;
            }
            if (!$verified) throw new GitHubAccessException('Your GitHub account does not have access to that installation.', 'Installation was absent from the authorizing user installations.', 'installation_not_authorized');
        } finally {
            $userToken = null;
        }

        $installation = self::request('GET', self::API . '/app/installations/' . $installationId, self::appJwt());
        if (($installation['suspended_at'] ?? null) !== null) {
            throw new GitHubAccessException('This GitHub App installation is suspended. Restore it in GitHub and reconnect.', 'Installation is suspended.', 'installation_suspended');
        }
        $permissions = is_array($installation['permissions'] ?? null) ? $installation['permissions'] : [];
        if (!in_array((string) ($permissions['contents'] ?? ''), ['read', 'write'], true)) {
            throw new GitHubAccessException('Repository contents permission is missing. Update the GitHub App installation and grant read access.', 'Installation lacks contents read permission.', 'permission_missing');
        }
        return $installation;
    }

    /** @return array<int, array<string, mixed>> */
    public static function repositoriesForUser(int $userId, int $installationRecordId): array
    {
        $installation = GitHubInstallationRepository::findForUser($installationRecordId, $userId);
        if ($installation === null) throw new GitHubAccessException('GitHub connection not found. Connect GitHub again.', 'Owner-scoped installation lookup failed.', 'installation_not_found');
        return self::withInstallationToken((int) $installation['github_installation_id'], null, static function (string $token): array {
            $repositories = [];
            for ($page = 1; $page <= 20; $page++) {
                $response = self::request('GET', self::API . '/installation/repositories?per_page=100&page=' . $page, $token);
                $items = is_array($response['repositories'] ?? null) ? $response['repositories'] : [];
                foreach ($items as $repository) {
                    $normalized = self::normalizeRepository($repository);
                    if ($normalized !== null) $repositories[] = $normalized;
                }
                if (count($items) < 100) break;
            }
            usort($repositories, static fn (array $a, array $b): int => strcasecmp((string) $a['full_name'], (string) $b['full_name']));
            return $repositories;
        });
    }

    /**
     * @template T
     * @param callable(array<string,mixed>, string):T $callback
     * @return T
     */
    public static function withRepositoryAccess(int $userId, int $installationRecordId, int $repositoryId, callable $callback): mixed
    {
        $installation = GitHubInstallationRepository::findForUser($installationRecordId, $userId);
        if ($installation === null) throw new GitHubAccessException('GitHub connection not found. Connect GitHub again.', 'Owner-scoped installation lookup failed.', 'installation_not_found');
        if ($repositoryId < 1) throw new GitHubAccessException('Choose a repository from the GitHub picker.', 'Invalid repository id.', 'repository_invalid');

        return self::withInstallationToken((int) $installation['github_installation_id'], [$repositoryId], static function (string $token) use ($repositoryId, $callback): mixed {
            try {
                $repository = self::request('GET', self::API . '/repositories/' . $repositoryId, $token);
            } catch (GitHubAccessException $exception) {
                if ($exception->reason === 'not_found') {
                    throw new GitHubAccessException('Repository not found or not granted to this GitHub App installation. Update repository access and try again.', $exception->getMessage(), 'repository_not_granted', $exception);
                }
                throw $exception;
            }
            $normalized = self::normalizeRepository($repository);
            if ($normalized === null || (int) $normalized['id'] !== $repositoryId) {
                throw new GitHubAccessException('GitHub returned an invalid repository record. Please reconnect and try again.', 'Repository identity validation failed.', 'repository_invalid');
            }
            return $callback($normalized, $token);
        });
    }

    /** @return array<string, mixed>|null */
    private static function normalizeRepository(array $repository): ?array
    {
        $id = (int) ($repository['id'] ?? 0);
        $fullName = (string) ($repository['full_name'] ?? '');
        if ($id < 1 || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $fullName)) return null;
        $url = RepositoryImporter::normalizeGithubUrl('https://github.com/' . $fullName);
        if ($url === null) return null;
        return [
            'id' => $id,
            'full_name' => $fullName,
            'name' => (string) ($repository['name'] ?? basename($fullName)),
            'owner' => (string) ($repository['owner']['login'] ?? explode('/', $fullName, 2)[0]),
            'private' => (bool) ($repository['private'] ?? false),
            'visibility' => (string) ($repository['visibility'] ?? ((bool) ($repository['private'] ?? false) ? 'private' : 'public')),
            'default_branch' => (string) ($repository['default_branch'] ?? ''),
            'repository_url' => $url,
        ];
    }

    /** @template T @param array<int,int>|null $repositoryIds @param callable(string):T $callback @return T */
    private static function withInstallationToken(int $installationId, ?array $repositoryIds, callable $callback): mixed
    {
        $payload = $repositoryIds === null ? [] : ['repository_ids' => array_values($repositoryIds)];
        try {
            $response = self::request('POST', self::API . '/app/installations/' . $installationId . '/access_tokens', self::appJwt(), $payload);
        } catch (GitHubAccessException $exception) {
            if ($exception->reason === 'not_found' || $exception->reason === 'permission_denied') {
                throw new GitHubAccessException('Your GitHub connection is no longer authorized. Reconnect GitHub to continue.', $exception->getMessage(), 'authorization_revoked', $exception);
            }
            if ($repositoryIds !== null) {
                throw new GitHubAccessException('WTFCode does not currently have access to this repository. Update your GitHub App installation and try again.', $exception->getMessage(), 'repository_not_granted', $exception);
            }
            throw $exception;
        }
        $token = (string) ($response['token'] ?? '');
        if ($token === '') throw new GitHubAccessException('GitHub could not issue temporary repository access. Reconnect and try again.', 'Installation token endpoint returned no token.', 'token_failed');
        try {
            return $callback($token);
        } finally {
            try { self::request('DELETE', self::API . '/installation/token', $token); }
            catch (Throwable $exception) { Logger::warning('GitHub installation token revocation failed', ['type' => get_class($exception)]); }
            $token = '';
        }
    }

    private static function appJwt(): string
    {
        self::requireConfigured();
        if (self::$testTransport !== null) return 'test.jwt.signature';
        $now = time();
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $config = self::configuration();
        $payload = self::base64Url(json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => (string) $config['github_app_id']], JSON_THROW_ON_ERROR));
        $keyText = str_replace('\\n', "\n", trim((string) $config['github_app_private_key']));
        if (!function_exists('openssl_pkey_get_private') || !function_exists('openssl_sign')) throw new GitHubAccessException('Private repository access is unavailable. Contact the WTFCode administrator.', 'PHP OpenSSL extension is unavailable.', 'configuration_invalid');
        $key = openssl_pkey_get_private($keyText);
        if ($key === false) throw new GitHubAccessException('GitHub App credentials are invalid. Contact the WTFCode administrator.', 'Private key could not be parsed.', 'configuration_invalid');
        $signature = '';
        if (!openssl_sign($header . '.' . $payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new GitHubAccessException('GitHub App credentials are invalid. Contact the WTFCode administrator.', 'JWT signature failed.', 'configuration_invalid');
        }
        return $header . '.' . $payload . '.' . self::base64Url($signature);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private static function request(string $method, string $url, ?string $bearer = null, ?array $body = null): array
    {
        if (!str_starts_with($url, self::API . '/') && $url !== self::OAUTH_TOKEN_URL) throw new LogicException('GitHub request host is not allowed.');
        if (self::$testTransport !== null) return (self::$testTransport)($method, $url, $bearer, $body);
        if (!function_exists('curl_init')) throw new GitHubAccessException('GitHub could not be reached. Try again shortly.', 'PHP cURL extension is unavailable.', 'unavailable');
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('GitHub HTTP client could not be initialized.');
        $headers = ['Accept: application/vnd.github+json', 'User-Agent: WTFCode-GitHub-App', 'X-GitHub-Api-Version: ' . self::API_VERSION];
        if ($bearer !== null) $headers[] = 'Authorization: Bearer ' . $bearer;
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw)) throw new GitHubAccessException('GitHub could not be reached. Try again shortly.', 'GitHub transport error: ' . $transportError, 'unavailable');
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($decoded)) $decoded = [];
        if ($status >= 200 && $status < 300) return $decoded;
        $reason = $status === 404 ? 'not_found' : ($status === 401 || $status === 403 ? 'permission_denied' : 'api_failed');
        throw new GitHubAccessException(
            $status === 404 ? 'GitHub resource not found or access was not granted.' : ($status === 401 || $status === 403 ? 'GitHub access was denied. Reconnect or update repository access.' : 'GitHub could not complete the request. Try again shortly.'),
            'GitHub API request failed with HTTP ' . $status . '.',
            $reason,
        );
    }

    private static function requireConfigured(): void
    {
        if (!self::configured()) throw new GitHubAccessException('Private repository access is not configured yet. Contact the WTFCode administrator.', 'GitHub App environment is incomplete.', 'not_configured');
    }

    /** @return array<string,mixed> */
    private static function configuration(): array
    {
        return self::$testConfiguration ?? app_config();
    }
}
