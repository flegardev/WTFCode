<?php

declare(strict_types=1);

namespace WTFCode\Repository;

use PDO;

final class ProjectRepository
{
    private readonly PDO $database;

    public function __construct(?PDO $database = null)
    {
        $this->database = $database ?? \Database::connection();
    }

    /** @return array<string, mixed>|null */
    public function findOwned(int $projectId, int $userId): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM projects WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute(['id' => $projectId, 'user_id' => $userId]);

        return $statement->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function highImpactFiles(int $projectId, int $limit = 8): array
    {
        $statement = $this->database->prepare(
            'SELECT pf.*, '
            . '(SELECT COUNT(*) FROM project_dependencies pd WHERE pd.target_file_id = pf.id) AS dependent_count, '
            . '(SELECT COUNT(*) FROM project_dependencies pd WHERE pd.source_file_id = pf.id) AS import_count '
            . 'FROM project_files pf WHERE pf.project_id = :project_id '
            . 'ORDER BY dependent_count DESC, pf.path ASC LIMIT :limit',
        );
        $statement->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function fileByPath(int $projectId, string $path): ?array
    {
        $statement = $this->database->prepare('SELECT id, path FROM project_files WHERE project_id = :project_id AND path = :path LIMIT 1');
        $statement->execute(['project_id' => $projectId, 'path' => $path]);

        return $statement->fetch() ?: null;
    }
}
