<?php

declare(strict_types=1);

final class ExplorationService
{
    /** @return array<int, array{key: string, title: string, description: string, href: string}> */
    public static function lessons(int $projectId): array
    {
        $lessons = [
            ['key' => 'overview', 'title' => 'Read the project overview', 'description' => 'Check the detected stack and its evidence before assuming how the app works.', 'href' => 'project.php?id=' . $projectId],
        ];
        foreach (Project::architecture($projectId)['nodes'] as $node) {
            $lessons[] = ['key' => 'node-' . $node['node_key'], 'title' => 'Understand ' . $node['label'], 'description' => $node['plain_explanation'], 'href' => 'feature.php?id=' . $projectId . '&feature=' . urlencode((string) $node['node_key'])];
        }
        $counts = SymbolRepository::counts($projectId);
        if ((int) ($counts['symbol_count'] ?? 0) > 0) {
            $lessons[] = ['key' => 'symbol-map', 'title' => 'Read the symbol graph', 'description' => 'Open a class, function, component, or table and distinguish confirmed internal edges from unresolved external targets.', 'href' => 'map.php?id=' . $projectId];
            $lessons[] = ['key' => 'blast-radius', 'title' => 'Reason about blast radius', 'description' => 'Start at one symbol, inspect direct and transitive consumers, then verify affected routes and data before editing.', 'href' => 'change.php?id=' . $projectId];
        }
        if ((int) ($counts['route_count'] ?? 0) > 0) $lessons[] = ['key' => 'route-flow', 'title' => 'Trace a request path', 'description' => 'Follow a detected route into its handler and connected symbols, checking confidence and source lines at every hop.', 'href' => 'routes.php?id=' . $projectId];
        if ((int) ($counts['table_count'] ?? 0) > 0) $lessons[] = ['key' => 'data-flow', 'title' => 'Follow the data flow', 'description' => 'Connect table or model evidence to the code that reads, writes, validates, and exposes it.', 'href' => 'tables.php?id=' . $projectId];
        $lessons[] = ['key' => 'file-map', 'title' => 'Browse the high-impact files', 'description' => 'Use the file map and dependency counts to locate the parts most likely to affect a change.', 'href' => 'files.php?id=' . $projectId];
        $lessons[] = ['key' => 'safe-prompt', 'title' => 'Build a change-safe prompt', 'description' => 'Create a scoped prompt based only on the systems and files the scan actually found.', 'href' => 'prompt.php?id=' . $projectId];
        if ((int) ($counts['symbol_count'] ?? 0) > 0) $lessons[] = ['key' => 'review-ai-change', 'title' => 'Review an AI-authored change', 'description' => 'Compare commits, inspect declaration deltas, and confirm that route, data, and security impact matches the requested change.', 'href' => 'compare.php?id=' . $projectId];
        return $lessons;
    }

    /** @return array{completed: array<int, string>, total: int, percentage: int} */
    public static function progress(int $userId, int $projectId): array
    {
        $lessons = self::lessons($projectId);
        $statement = Database::connection()->prepare('SELECT lesson_key FROM learning_progress WHERE user_id = :user_id AND project_id = :project_id');
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId]);
        $completed = array_values(array_intersect($statement->fetchAll(PDO::FETCH_COLUMN), array_column($lessons, 'key')));
        $total = count($lessons);
        return ['completed' => $completed, 'total' => $total, 'percentage' => $total === 0 ? 0 : (int) floor((count($completed) / $total) * 100)];
    }

    public static function complete(int $userId, int $projectId, string $lessonKey): bool
    {
        $valid = array_column(self::lessons($projectId), 'key');
        if (!in_array($lessonKey, $valid, true)) return false;
        $statement = Database::connection()->prepare('INSERT IGNORE INTO learning_progress (user_id, project_id, lesson_key) VALUES (:user_id, :project_id, :lesson_key)');
        $statement->execute(['user_id' => $userId, 'project_id' => $projectId, 'lesson_key' => $lessonKey]);
        return true;
    }

    public static function workspacePercentage(int $userId): int
    {
        $projects = Project::allForUser($userId);
        if ($projects === []) return 0;
        $sum = 0;
        foreach ($projects as $project) $sum += self::progress($userId, (int) $project['id'])['percentage'];
        return (int) floor($sum / count($projects));
    }
}
