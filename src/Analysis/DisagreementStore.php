<?php

declare(strict_types=1);

final class DisagreementStore
{
    public static function persist(int $projectId, int $scanRunId, array $disagreements): void
    {
        $statement = Database::connection()->prepare('INSERT INTO engine_disagreements (project_id, scan_run_id, fact_identity, fact_type, provider_results_json, resolution) VALUES (:project_id, :scan_run_id, :identity, :type, :results, :resolution)');
        foreach (array_slice($disagreements, 0, 2000) as $item) $statement->execute(['project_id' => $projectId, 'scan_run_id' => $scanRunId, 'identity' => $item['identity'], 'type' => substr((string) $item['type'], 0, 80), 'results' => json_encode(SensitiveDataSanitizer::scrub($item['provider_results']), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'resolution' => substr((string) $item['resolution'], 0, 500)]);
    }
}
