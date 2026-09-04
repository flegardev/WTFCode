<?php

declare(strict_types=1);

namespace WTFCode\Application;

use WTFCode\Repository\ProjectRepository;

final class ProjectOverviewService
{
    public function __construct(private readonly ProjectRepository $projects = new ProjectRepository()) {}

    /**
     * @return array{project: array<string, mixed>, architecture: array<string, mixed>, findings: list<array<string, mixed>>, files: list<array<string, mixed>>, stack: list<mixed>, progress: array<string, mixed>, symbol_counts: array<string, mixed>, external_services: list<array<string, mixed>>, latest_scan: array<string, mixed>|null, latest_job: array<string, mixed>|null}|null
     */
    public function forUser(int $projectId, int $userId): ?array
    {
        $project = $this->projects->findOwned($projectId, $userId);
        if ($project === null) {
            return null;
        }

        $services = \SymbolRepository::services($projectId);

        return [
            'project' => $project,
            'architecture' => \Project::architecture($projectId),
            'findings' => \Project::findings($projectId, 100),
            'files' => $this->projects->highImpactFiles($projectId),
            'stack' => $this->decodeList($project['stack_json'] ?? null),
            'progress' => \ExplorationService::progress($userId, $projectId),
            'symbol_counts' => \SymbolRepository::counts($projectId),
            'external_services' => array_values(array_filter(
                $services,
                static fn(array $item): bool => ($item['symbol_type'] ?? '') === 'external_service',
            )),
            'latest_scan' => \SymbolRepository::latestScan($projectId),
            'latest_job' => \AnalysisJobStore::latestForUser($projectId, $userId),
        ];
    }

    /** @return list<mixed> */
    private function decodeList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }
}
