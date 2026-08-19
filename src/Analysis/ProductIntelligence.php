<?php

declare(strict_types=1);

/**
 * Adds product meaning to the fused graph without executing repository code.
 * Every derived fact keeps its source line and the independent signals used.
 */
final class ProductIntelligence
{
    private const FEATURES = [
        'Login' => ['login', 'sign in', 'signin', 'authenticate', 'session'],
        'Registration' => ['register', 'sign up', 'signup', 'create account'],
        'Checkout' => ['checkout', 'cart', 'place order'],
        'Payments' => ['payment', 'stripe', 'invoice', 'billing portal'],
        'Upload' => ['upload', 'attachment', 'multipart', 'file storage'],
        'Profile' => ['profile', 'account settings', 'avatar'],
        'Search' => ['search', 'query', 'filter results'],
        'Admin' => ['admin', 'moderation', 'manage users'],
        'Billing' => ['billing', 'subscription', 'plan', 'invoice'],
        'Notifications' => ['notification', 'mailer', 'email', 'web push'],
        'AI chat' => ['chat', 'completion', 'openai', 'anthropic', 'ollama'],
        'Repository import' => ['repository import', 'repo import', 'clone repository', 'repositoryimporter'],
        'Scanning' => ['repository scan', 'repo scan', 'scanner', 'analysis provider'],
        'Git review' => ['git diff', 'commit compare', 'change guard', 'scope drift'],
    ];

    private const SERVICES = [
        'Supabase' => ['supabase', 'supabase.co'], 'Firebase' => ['firebase', 'firestore'],
        'Stripe' => ['stripe', 'api.stripe.com'], 'OpenAI' => ['openai', 'api.openai.com'],
        'Anthropic' => ['anthropic', 'api.anthropic.com'], 'AWS' => ['aws-sdk', 'amazonaws.com'],
        'S3' => ['s3client', 's3://'], 'Redis' => ['redis', 'rediss://'],
        'Postgres' => ['postgres', 'pgsql:', 'postgresql://'], 'MySQL' => ['mysql:', 'mysqli', 'mysql2'],
        'MongoDB' => ['mongodb', 'mongoose'], 'Sentry' => ['sentry', 'sentry.io'],
        'Vercel' => ['vercel.json', '@vercel/'], 'Cloudflare' => ['cloudflare', 'wrangler'],
        'Docker' => ['dockerfile', 'docker-compose'], 'Kubernetes' => ['kubernetes', 'apiVersion:', 'kind: deployment'],
    ];

    /** @param array<string, mixed> $graph @param array<int, array<string, mixed>> $files */
    public function enrich(array &$graph, array $files): array
    {
        $fileMap = [];
        foreach ($files as $file) $fileMap[(string) $file['path']] = $file;
        $symbols = $graph['symbols'] ?? [];
        $relationships = $graph['relationships'] ?? [];
        $routes = $graph['routes'] ?? [];
        unset($graph['symbols'], $graph['relationships'], $graph['routes']);
        $ranges = $this->symbolRanges($symbols);
        $symbolIndex = [];
        foreach ($symbols as $index => $symbol) $symbolIndex[(string) $symbol['key']] = $index;
        $relationshipBudget = count($relationships) > 12_000 ? 400 : (count($relationships) > 8_000 ? 800 : 2_500);
        $symbolBudget = count($symbols) > 7_000 ? 100 : 600;
        $derivedRelationships = 0;
        $derivedSymbols = 0;
        $databaseOperationCount = 0;
        $controlFactCount = 0;

        foreach ($fileMap as $path => $file) {
            $content = (string) ($file['content'] ?? '');
            if ($content === '') continue;
            $lines = preg_split('/\R/', $content) ?: [];
            foreach ($lines as $offset => $line) {
                $lineNumber = $offset + 1;
                $sourceKey = $this->sourceAt($ranges[$path] ?? [], $lineNumber);
                foreach ($this->databaseOperations($line) as $operation) {
                    if ($derivedRelationships >= $relationshipBudget) break;
                    $tableKey = $this->tableKey($symbols, $path, $lineNumber, $operation['table']);
                    if (!isset($symbolIndex[$tableKey]) && $derivedSymbols < $symbolBudget) {
                        $symbols[] = $this->derivedSymbol($tableKey, $path, (string) ($file['language'] ?? 'Unknown'), 'table', $operation['table'], $lineNumber, ['reference_only' => true]);
                        $symbolIndex[$tableKey] = array_key_last($symbols);
                        $derivedSymbols++;
                    }
                    if (!isset($symbolIndex[$tableKey])) continue;
                    $relationships[] = $this->derivedRelationship($sourceKey, $tableKey, null, $operation['relationship'], $path, $lineNumber, $line, [
                        'database_operation' => $operation['operation'], 'table' => $operation['table'],
                    ]);
                    $derivedRelationships++;
                    $databaseOperationCount++;
                }
                foreach ($this->controlFacts($line) as $control) {
                    if ($derivedRelationships >= $relationshipBudget) break;
                    $relationships[] = $this->derivedRelationship($sourceKey, null, 'control:' . $control['kind'], $control['relationship'], $path, $lineNumber, $line, ['control_flow' => $control['kind']]);
                    $derivedRelationships++;
                    $controlFactCount++;
                }
                foreach ($this->uiAndTransportFacts($line) as $fact) {
                    if ($derivedRelationships >= $relationshipBudget) break;
                    $relationships[] = $this->derivedRelationship($sourceKey, null, $fact['target'], $fact['relationship'], $path, $lineNumber, $line, $fact['metadata']);
                    $derivedRelationships++;
                }
            }
            if (!$this->runtimeEvidencePath($path)) continue;
            foreach (self::SERVICES as $service => $needles) {
                $matches = [];
                foreach ($needles as $needle) {
                    if (stripos($content, $needle) !== false) $matches[] = $needle;
                }
                if ($matches === []) continue;
                $lineNumber = $this->firstLine($content, $matches[0]);
                $sourceKey = $this->sourceAt($ranges[$path] ?? [], $lineNumber);
                $serviceKey = hash('sha256', $path . '|external_service|service:' . strtolower($service) . '|' . $lineNumber);
                if (!isset($symbolIndex[$serviceKey]) && $derivedSymbols < $symbolBudget) {
                    $symbols[] = $this->derivedSymbol($serviceKey, $path, (string) ($file['language'] ?? 'Unknown'), 'external_service', $service, $lineNumber, ['service' => $service, 'runtime_evidence' => true, 'matched_signals' => $matches]);
                    $symbolIndex[$serviceKey] = array_key_last($symbols);
                    $derivedSymbols++;
                }
                if (!isset($symbolIndex[$serviceKey]) || $derivedRelationships >= $relationshipBudget) continue;
                $relationships[] = $this->derivedRelationship($sourceKey, $serviceKey, null, 'uses_service', $path, $lineNumber, '', ['service' => $service, 'matched_signals' => $matches]);
                $derivedRelationships++;
            }
        }

        $features = $this->detectFeatures($symbols, $relationships, $routes, $fileMap);
        $routes = $this->enrichRoutes($routes, $relationships, $symbols, $fileMap);
        $graph['symbols'] = $symbols;
        $graph['relationships'] = $relationships;
        $graph['routes'] = $routes;
        $graph['features'] = $features;
        $graph['stats']['features'] = count($features);
        $graph['stats']['database_operations'] = $databaseOperationCount;
        $graph['stats']['control_flow_facts'] = $controlFactCount;
        $graph['stats']['product_intelligence_limited'] = $derivedRelationships >= $relationshipBudget || $derivedSymbols >= $symbolBudget ? 1 : 0;
        return $graph;
    }

    /** @param array<int, array<string, mixed>> $symbols @return array<string, array<int, array<string, mixed>>> */
    private function symbolRanges(array $symbols): array
    {
        $ranges = [];
        foreach ($symbols as $symbol) $ranges[(string) $symbol['path']][] = ['key' => $symbol['key'], 'type' => $symbol['type'], 'start_line' => $symbol['start_line'], 'end_line' => $symbol['end_line']];
        foreach ($ranges as &$items) usort($items, static fn (array $a, array $b): int => (($a['end_line'] - $a['start_line']) <=> ($b['end_line'] - $b['start_line'])));
        return $ranges;
    }

    /** @param array<int, array<string, mixed>> $ranges */
    private function sourceAt(array $ranges, int $line): ?string
    {
        foreach ($ranges as $symbol) if ($line >= (int) $symbol['start_line'] && $line <= (int) $symbol['end_line']) return (string) $symbol['key'];
        foreach ($ranges as $symbol) if (($symbol['type'] ?? '') === 'module') return (string) $symbol['key'];
        return null;
    }

    /** @return array<int, array{operation: string, relationship: string, table: string}> */
    private function databaseOperations(string $line): array
    {
        $patterns = [
            ['READ', 'reads_table', '/\bSELECT\b.{0,500}?\b(?:FROM|JOIN)\s+[`"\[]?([A-Za-z_][A-Za-z0-9_.]*)/i'],
            ['CREATE', 'creates_in_table', '/\bINSERT\s+(?:IGNORE\s+)?INTO\s+[`"\[]?([A-Za-z_][A-Za-z0-9_.]*)/i'],
            ['UPDATE', 'updates_table', '/\bUPDATE\s+[`"\[]?([A-Za-z_][A-Za-z0-9_.]*)\s+(?:SET|AS)\b/i'],
            ['DELETE', 'deletes_from_table', '/\bDELETE\s+FROM\s+[`"\[]?([A-Za-z_][A-Za-z0-9_.]*)/i'],
            ['DDL', 'defines_table', '/\b(?:CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`"\[]?([A-Za-z_][A-Za-z0-9_.]*)/i'],
        ];
        $facts = [];
        foreach ($patterns as [$operation, $relationship, $pattern]) {
            if (preg_match_all($pattern, $line, $matches)) foreach ($matches[1] as $table) $facts[] = compact('operation', 'relationship', 'table');
        }
        $builder = [
            'READ' => '/(?:\.select|->get|->first|::find|\.findMany|\.findUnique)\s*\(/i',
            'CREATE' => '/(?:\.insert|->insert|::create|\.create)\s*\(/i',
            'UPDATE' => '/(?:\.update|->update|\.upsert|->upsert)\s*\(/i',
            'DELETE' => '/(?:\.delete|->delete|::destroy)\s*\(/i',
        ];
        if (preg_match('/(?:from|table)\s*\(\s*[\'\"]([A-Za-z_][A-Za-z0-9_.]*)[\'\"]\s*\)/i', $line, $table)) {
            foreach ($builder as $operation => $pattern) if (preg_match($pattern, $line)) {
                $relationship = match ($operation) { 'READ' => 'reads_table', 'CREATE' => 'creates_in_table', 'UPDATE' => 'updates_table', default => 'deletes_from_table' };
                $facts[] = ['operation' => $operation, 'relationship' => $relationship, 'table' => $table[1]];
            }
        }
        return array_values(array_unique($facts, SORT_REGULAR));
    }

    /** @return array<int, array{kind: string, relationship: string}> */
    private function controlFacts(string $line): array
    {
        $facts = [];
        foreach ([
            'authorization' => ['/\b(?:authorize|authorization|permission|policy|can\s*\(|requireRole|requireLogin)\b/i', 'authorizes'],
            'validation' => ['/\b(?:validate|validation|filter_var|safeParse|isValid)\b/i', 'validates'],
            'condition' => ['/\bif\s*\(|\bif\s+.+:/i', 'branches_if'],
            'return' => ['/\breturn\b/i', 'returns'], 'throw' => ['/\bthrow\b|\braise\b/i', 'throws'],
            'redirect' => ['/\bredirect\b|\bheader\s*\(\s*[\'\"]Location:/i', 'redirects'],
            'error handling' => ['/\bcatch\s*\(|\bexcept\b/i', 'handles_error'],
            'success' => ['/\b(?:success|completed|ok\s*[:=]|status\s*[:=]\s*2\d\d)\b/i', 'success_path'],
        ] as $kind => [$pattern, $relationship]) if (preg_match($pattern, $line)) $facts[] = compact('kind', 'relationship');
        return $facts;
    }

    /** @return array<int, array{target: string, relationship: string, metadata: array<string, mixed>}> */
    private function uiAndTransportFacts(string $line): array
    {
        $facts = [];
        if (preg_match_all('/\b(onClick|onSubmit|onChange|addEventListener)\b/i', $line, $matches)) foreach ($matches[1] as $event) {
            $facts[] = ['target' => 'ui:' . strtolower($event), 'relationship' => 'handles_ui_event', 'metadata' => ['ui_event' => $event]];
        }
        if (preg_match('/\bfetch\s*\(\s*[\'\"`]([^\'\"`]+)|\baxios\.(get|post|put|patch|delete)\s*\(\s*[\'\"`]([^\'\"`]+)/i', $line, $match)) {
            $endpoint = $match[1] ?: ($match[3] ?? 'dynamic endpoint');
            $method = isset($match[2]) && $match[2] !== '' ? strtoupper($match[2]) : (preg_match('/method\s*:\s*[\'\"]([A-Z]+)/i', $line, $methodMatch) ? strtoupper($methodMatch[1]) : 'GET');
            $facts[] = ['target' => 'http:' . $method . ' ' . $endpoint, 'relationship' => 'http_request', 'metadata' => ['method' => $method, 'endpoint' => $endpoint]];
        }
        return $facts;
    }

    /** @param array<int, array<string, mixed>> $symbols */
    private function tableKey(array $symbols, string $path, int $line, string $table): string
    {
        foreach ($symbols as $symbol) if (($symbol['type'] ?? '') === 'table' && strtolower((string) $symbol['name']) === strtolower($table)) return (string) $symbol['key'];
        return hash('sha256', $path . '|table|table:' . strtolower($table) . '|' . $line);
    }

    /** @return array<string, mixed> */
    private function derivedSymbol(string $key, string $path, string $language, string $type, string $name, int $line, array $metadata): array
    {
        return ['key' => $key, 'path' => $path, 'language' => $language, 'type' => $type, 'name' => $name, 'qualified_name' => ($type === 'table' ? 'table:' : 'service:') . strtolower($name), 'parent_key' => null, 'signature' => null, 'visibility' => 'unknown', 'exported' => false, 'start_line' => $line, 'end_line' => $line, 'confidence' => 'medium', 'metadata' => $metadata + ['derived_by' => 'wtfcode-product-intelligence', 'confidence_label' => 'likely']];
    }

    /** @return array<string, mixed> */
    private function derivedRelationship(?string $source, ?string $target, ?string $external, string $type, string $path, int $line, string $excerpt, array $metadata): array
    {
        return ['source_key' => $source, 'target_key' => $target, 'external_name' => $external, 'target_name' => $external ?? '', 'type' => $type, 'confidence' => 'medium', 'evidence_path' => $path, 'line_start' => $line, 'line_end' => $line, 'excerpt' => function_exists('mb_substr') ? mb_substr(trim($excerpt), 0, 300) : substr(trim($excerpt), 0, 300), 'metadata' => $metadata + ['derived_by' => 'wtfcode-product-intelligence', 'confidence_label' => 'likely']];
    }

    /** @return array<int, array<string, mixed>> */
    private function detectFeatures(array &$symbols, array $relationships, array $routes, array $fileMap): array
    {
        $edgeSignals = [];
        foreach ($relationships as $edge) {
            $source = $edge['source_key'] ?? null;
            if ($source === null || strlen($edgeSignals[$source] ?? '') >= 500) continue;
            $edgeSignals[$source] = ($edgeSignals[$source] ?? '') . ' ' . strtolower(implode(' ', [(string) ($edge['type'] ?? ''), (string) ($edge['target_name'] ?? ''), (string) ($edge['external_name'] ?? '')]));
        }
        $fileFeatureSignals = [];
        foreach ($fileMap as $path => $file) {
            $content = (string) ($file['content'] ?? '');
            foreach (self::FEATURES as $feature => $needles) {
                foreach ($needles as $needle) if (stripos($content, $needle) !== false) $fileFeatureSignals[$path][$feature][] = $needle;
            }
        }
        $clusters = [];
        foreach ($symbols as &$symbol) {
            $path = (string) $symbol['path'];
            $identity = strtolower(implode(' ', [(string) $symbol['name'], (string) $symbol['qualified_name'], $path]));
            $edgeText = $edgeSignals[$symbol['key']] ?? '';
            $routeText = '';
            foreach ($routes as $route) if (($route['path'] ?? '') === $path || ($route['handler_key'] ?? null) === $symbol['key']) $routeText .= ' ' . strtolower((string) $route['route_path']);
            foreach (self::FEATURES as $feature => $needles) {
                $signals = [];
                foreach ($needles as $needle) {
                    if (str_contains($identity, $needle)) $signals['identity'][] = $needle;
                    if ($edgeText !== '' && str_contains($edgeText, $needle)) $signals['graph'][] = $needle;
                    if ($routeText !== '' && str_contains($routeText, $needle)) $signals['route'][] = $needle;
                    if (in_array($needle, $fileFeatureSignals[$path][$feature] ?? [], true)) $signals['source'][] = $needle;
                }
                if (count($signals) < 2) continue;
                $score = count($signals) * 2 + array_sum(array_map('count', $signals));
                $symbol['metadata']['features'][] = $feature;
                $clusters[$feature]['label'] = $feature;
                $clusters[$feature]['score'] = ($clusters[$feature]['score'] ?? 0) + $score;
                $clusters[$feature]['symbols'][] = $symbol['key'];
                $clusters[$feature]['evidence'][] = ['path' => $path, 'line' => (int) $symbol['start_line'], 'signals' => array_keys($signals), 'confidence' => count($signals) >= 3 ? 'strong' : 'likely'];
            }
            if (isset($symbol['metadata']['features'])) $symbol['metadata']['features'] = array_values(array_unique($symbol['metadata']['features']));
        }
        unset($symbol);
        foreach ($clusters as &$cluster) {
            $cluster['symbols'] = array_values(array_unique($cluster['symbols']));
            $cluster['evidence'] = array_slice(array_values(array_unique($cluster['evidence'], SORT_REGULAR)), 0, 20);
            $cluster['confidence'] = count($cluster['symbols']) >= 2 || count($cluster['evidence']) >= 3 ? 'strong' : 'likely';
            $cluster['partial'] = true;
        }
        unset($cluster);
        uasort($clusters, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_values($clusters);
    }

    /** @param array<int, array<string, mixed>> $routes */
    private function enrichRoutes(array $routes, array $relationships, array $symbols, array $fileMap): array
    {
        $symbolByKey = [];
        foreach ($symbols as $symbol) $symbolByKey[$symbol['key']] = ['name' => $symbol['name'], 'features' => $symbol['metadata']['features'] ?? []];
        foreach ($routes as &$route) {
            $handler = $route['handler_key'] ?? null;
            $path = (string) $route['path'];
            $content = (string) ($fileMap[$path]['content'] ?? '');
            $effects = ['db' => [], 'external' => [], 'control' => []];
            foreach ($relationships as $edge) {
                if (($edge['source_key'] ?? null) !== $handler && ($edge['evidence_path'] ?? '') !== $path) continue;
                if (isset($edge['metadata']['database_operation'])) $effects['db'][] = $edge['metadata']['database_operation'] . ' ' . ($edge['metadata']['table'] ?? 'table');
                if (in_array($edge['type'] ?? '', ['calls_service', 'uses_service', 'http_request'], true)) $effects['external'][] = $edge['external_name'] ?? ($symbolByKey[$edge['target_key'] ?? '']['name'] ?? 'external service');
                if (isset($edge['metadata']['control_flow'])) $effects['control'][] = $edge['metadata']['control_flow'];
            }
            foreach ($effects as &$items) $items = array_values(array_unique($items)); unset($items);
            $route['metadata']['endpoint_type'] = $this->endpointType($route, $content);
            $route['metadata']['auth'] = preg_match('/\b(auth|authorize|permission|middleware|session|jwt|bearer)\b/i', $content) ? 'evidence present' : 'not proven';
            $route['metadata']['input_hints'] = $this->inputHints($content);
            $route['metadata']['response_hints'] = $this->responseHints($content);
            $route['metadata']['effects'] = $effects;
            $route['metadata']['features'] = $handler !== null ? ($symbolByKey[$handler]['features'] ?? []) : [];
            $route['metadata']['partial'] = true;
        }
        unset($route);
        return $routes;
    }

    private function endpointType(array $route, string $content): string
    {
        $routePath = (string) ($route['route_path'] ?? '');
        return match (true) {
            stripos($routePath, 'graphql') !== false || stripos($content, 'graphql') !== false => 'GraphQL',
            stripos($routePath, 'webhook') !== false || stripos($content, 'webhook') !== false => 'Webhook',
            stripos($routePath, 'websocket') !== false || stripos($content, 'websocket') !== false || stripos($content, 'socket.io') !== false => 'WebSocket',
            stripos($routePath, 'rpc') !== false || stripos($content, 'rpc') !== false => 'RPC',
            stripos($content, 'use server') !== false => 'Server action',
            default => 'REST',
        };
    }

    /** @return array<int, string> */
    private function inputHints(string $content): array
    {
        $hints = [];
        foreach (['JSON body' => '/json_decode|\.json\(\)|request\.body/i', 'form fields' => '/FormData|\$_POST|request->input/i', 'query parameters' => '/\$_GET|searchParams|request\.query/i', 'file upload' => '/multipart|\$_FILES|upload/i'] as $label => $pattern) if (preg_match($pattern, $content)) $hints[] = $label;
        return $hints;
    }

    /** @return array<int, string> */
    private function responseHints(string $content): array
    {
        $hints = [];
        foreach (['JSON' => '/json_encode|NextResponse::json|Response\.json|JsonResponse/i', 'redirect' => '/redirect|Location:/i', 'HTML/view' => '/render|view\s*\(|require.+view/i', 'error' => '/throw|catch|error|status\s*[:=]\s*[45]\d\d/i'] as $label => $pattern) if (preg_match($pattern, $content)) $hints[] = $label;
        return $hints;
    }

    private function runtimeEvidencePath(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $path));
        return !preg_match('#(^|/)(?:docs?|tests?|fixtures?|examples?|vendor|node_modules)(/|$)|(?:readme|changelog|license)\.(?:md|txt)$#', $normalized);
    }

    private function firstLine(string $content, string $needle): int
    {
        $position = stripos($content, $needle);
        return $position === false ? 1 : substr_count(substr($content, 0, $position), "\n") + 1;
    }
}
