<?php

declare(strict_types=1);

namespace WTFCode\Repository;

use Database;
use PDO;

final class TestEvidenceRepository
{
    /**
     * Return only test-like paths that were actually observed in the scan.
     * The final relevance ranking stays in PHP so the query remains portable
     * across PostgreSQL and MySQL.
     *
     * @param array<int, string> $changedPaths
     * @param array<int, string> $symbolNames
     * @return array<int, array<string, mixed>>
     */
    public function likelyForChange(int $projectId, array $changedPaths, array $symbolNames, int $limit = 12): array
    {
        $statement = Database::connection()->prepare(
            "SELECT id, path, role_name, language FROM project_files
             WHERE project_id = :project_id
               AND (LOWER(path) LIKE :test_path OR LOWER(path) LIKE :spec_path OR LOWER(role_name) LIKE :test_role)
             ORDER BY path
             LIMIT 400",
        );
        $statement->execute([
            'project_id' => $projectId,
            'test_path' => '%test%',
            'spec_path' => '%spec%',
            'test_role' => '%test%',
        ]);

        $needles = [];
        foreach (array_merge($changedPaths, $symbolNames) as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $base = pathinfo(str_replace('\\', '/', $value), PATHINFO_FILENAME);
            $normalized = self::normalize($base);
            if (strlen($normalized) >= 3) {
                $needles[$normalized] = true;
            }
        }

        $ranked = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $file) {
            $normalizedPath = self::normalize((string) $file['path']);
            $matches = [];
            foreach (array_keys($needles) as $needle) {
                if (str_contains($normalizedPath, $needle)) {
                    $matches[] = $needle;
                }
            }
            $file['matched_terms'] = array_slice($matches, 0, 5);
            $file['match_score'] = count($matches);
            $ranked[] = $file;
        }

        usort(
            $ranked,
            static fn(array $left, array $right): int =>
            ((int) $right['match_score'] <=> (int) $left['match_score'])
            ?: strcmp((string) $left['path'], (string) $right['path']),
        );

        $matched = array_values(array_filter($ranked, static fn(array $file): bool => (int) $file['match_score'] > 0));
        return array_slice($matched !== [] ? $matched : $ranked, 0, max(1, min($limit, 30)));
    }

    private static function normalize(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? $value);
    }
}
