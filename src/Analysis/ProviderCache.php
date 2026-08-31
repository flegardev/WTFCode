<?php

declare(strict_types=1);

final class ProviderCache
{
    private string $root;
    private bool $databaseBacked;

    public function __construct(?string $root = null)
    {
        $this->databaseBacked = $root === null && (app_config()['environment'] ?? 'local') === 'production' && Database::isPostgres();
        $this->root = $root ?? app_config()['storage_path'] . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'providers';
    }

    public function key(AnalysisRequest $request, AnalyzerProviderInterface $provider, ?string $revision = null): string
    {
        return hash('sha256', implode('|', [$revision ?? $request->revision(), $provider->id(), $provider->version(), AnalysisEngine::VERSION, $this->configurationHash($request, $provider)]));
    }

    public function load(AnalysisRequest $request, AnalyzerProviderInterface $provider, ?string $revision = null): ?AnalyzerResult
    {
        $key = $this->key($request, $provider, $revision);
        if ($this->databaseBacked) {
            try {
                $statement = Database::connection()->prepare('SELECT result_json FROM provider_cache_entries WHERE cache_key = :key AND expires_at > CURRENT_TIMESTAMP LIMIT 1');
                $statement->execute(['key' => $key]);
                $json = $statement->fetchColumn();
                if (!is_string($json) || strlen($json) > 20_971_520) return null;
            } catch (Throwable) { return null; }
        } else {
            $path = $this->path($provider->id(), $key);
            if (!is_file($path) || filesize($path) > 20_971_520) return null;
            $json = (string) file_get_contents($path);
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ($decoded['cache_key'] ?? '') !== $key) return null;
        $result = $decoded['result'] ?? null;
        if (!is_array($result) || !in_array($result['status'] ?? '', [AnalyzerResult::SUCCESS, AnalyzerResult::PARTIAL], true)) return null;
        return new AnalyzerResult(
            (string) $result['engine'], (string) $result['version'], (string) $result['status'],
            is_array($result['graph'] ?? null) ? $result['graph'] : [], is_array($result['findings'] ?? null) ? $result['findings'] : [],
            0, 'Reused content-addressed provider cache.', ['cache_hit' => true, 'incremental' => false, 'files_analyzed' => 0, 'cache_key' => $key],
        );
    }

    public function save(AnalysisRequest $request, AnalyzerProviderInterface $provider, AnalyzerResult $result): ?string
    {
        if (!in_array($result->status, [AnalyzerResult::SUCCESS, AnalyzerResult::PARTIAL], true)) return null;
        if (count($result->graph['relationships'] ?? []) > 8000 || count($result->graph['symbols'] ?? []) > 5000) return null;
        $key = $this->key($request, $provider);
        $payload = SensitiveDataSanitizer::scrub(['cache_key' => $key, 'created_at' => gmdate(DATE_ATOM), 'result' => ['engine' => $result->engine, 'version' => $result->engineVersion, 'status' => $result->status, 'graph' => $result->graph, 'findings' => $result->findings]]);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > 16_777_216) return null;
        if ($this->databaseBacked) {
            try {
                $sql = 'INSERT INTO provider_cache_entries (cache_key, provider_id, provider_version, analysis_version, result_json, expires_at, updated_at) VALUES (:key, :provider, :version, :analysis, :result, :expires, CURRENT_TIMESTAMP) ON CONFLICT (cache_key) DO UPDATE SET provider_id = EXCLUDED.provider_id, provider_version = EXCLUDED.provider_version, analysis_version = EXCLUDED.analysis_version, result_json = EXCLUDED.result_json, expires_at = EXCLUDED.expires_at, updated_at = CURRENT_TIMESTAMP';
                Database::connection()->prepare($sql)->execute([
                    'key' => $key, 'provider' => substr($provider->id(), 0, 80), 'version' => substr($provider->version(), 0, 100),
                    'analysis' => AnalysisEngine::VERSION, 'result' => $json, 'expires' => gmdate('Y-m-d H:i:sP', time() + 604800),
                ]);
                return $key;
            } catch (Throwable) { return null; }
        }
        $directory = dirname($this->path($provider->id(), $key));
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) return null;
        $temporary = tempnam($directory, 'cache-');
        if ($temporary === false) return null;
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) === false) return null;
            @chmod($temporary, 0600);
            if (!@rename($temporary, $this->path($provider->id(), $key))) return null;
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
        return $key;
    }

    private function configurationHash(AnalysisRequest $request, AnalyzerProviderInterface $provider): string
    {
        $configs = [dirname(__DIR__, 2) . '/config/tool-manifest.json', dirname(__DIR__, 2) . '/config/security/gitleaks.toml', dirname(__DIR__, 2) . '/config/security/semgrep.yml'];
        $hashes = [];
        foreach ($configs as $file) if (is_file($file)) $hashes[] = basename($file) . ':' . hash_file('sha256', $file);
        $implementation = [];
        foreach ($this->implementationFiles($provider) as $file) $implementation[str_replace('\\', '/', basename($file))] = hash_file('sha256', $file);
        return hash('sha256', json_encode(['profile' => $request->profile(), 'capabilities' => $provider->capabilities(), 'implementation' => $implementation, 'configs' => $hashes], JSON_UNESCAPED_SLASHES));
    }

    /** @return array<int,string> */
    private function implementationFiles(AnalyzerProviderInterface $provider): array
    {
        $reflection = new ReflectionClass($provider);
        $classFile = $reflection->getFileName();
        $files = is_string($classFile) && is_file($classFile) ? [$classFile] : [];
        if ($provider->id() !== 'wtfcode-native') return $files;
        $directory = __DIR__;
        foreach (array_merge(glob($directory . '/*Adapter.php') ?: [], [$directory . '/AnalysisEngine.php', $directory . '/SymbolGraph.php', $directory . '/AbstractLanguageAdapter.php', $directory . '/AbstractFrameworkAdapter.php']) as $file) {
            if (is_file($file)) $files[] = $file;
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);
        return $files;
    }

    private function path(string $provider, string $key): string
    {
        $safe = preg_replace('/[^a-z0-9._-]/i', '_', $provider) ?: 'unknown';
        return $this->root . DIRECTORY_SEPARATOR . $safe . DIRECTORY_SEPARATOR . $key . '.json';
    }
}
