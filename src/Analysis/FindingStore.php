<?php

declare(strict_types=1);

final class FindingStore
{
    /** @param array<int, array<string, mixed>> $findings */
    public static function persist(int $projectId, int $scanRunId, array $findings): void
    {
        $statement = Database::connection()->prepare('INSERT INTO scan_findings (project_id, scan_run_id, severity, finding_type, title, plain_explanation, file_path, evidence_json) VALUES (:project_id, :scan_run_id, :severity, :finding_type, :title, :plain_explanation, :file_path, :evidence_json)');
        foreach ($findings as $finding) {
            if (!is_array($finding)) continue;
            $finding = SensitiveDataSanitizer::finding($finding);
            $severity = in_array($finding['severity'] ?? '', ['info', 'attention', 'risk'], true) ? $finding['severity'] : 'info';
            $statement->execute([
                'project_id' => $projectId,
                'scan_run_id' => $scanRunId,
                'severity' => $severity,
                'finding_type' => substr((string) ($finding['type'] ?? 'unknown'), 0, 80),
                'title' => substr(SensitiveDataSanitizer::text((string) ($finding['title'] ?? 'Review finding')), 0, 255),
                'plain_explanation' => SensitiveDataSanitizer::text((string) ($finding['explanation'] ?? 'Review the attached evidence.')),
                'file_path' => ($finding['path'] ?? null) === null ? null : substr((string) $finding['path'], 0, 500),
                'evidence_json' => json_encode($finding['evidence'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
    }
}
