<?php

declare(strict_types=1);

namespace WTFCode\Application;

use Database;
use Throwable;

final class AccountData
{
    /** Only the authenticated caller supplies the ID, never an HTTP parameter. */
    public static function export(int $userId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $q = $pdo->prepare('SELECT id, name, email, email_verified_at, created_at FROM users WHERE id = ?');
            $q->execute([$userId]);
            $result = ['exported_at' => gmdate(DATE_ATOM), 'scope' => 'Stored account and project metadata; not a full source-code archive.', 'account' => $q->fetch(), 'projects' => [], 'feedback' => []];
            $q = $pdo->prepare('SELECT id, name, repository_url, status, overview, stack_json, last_scan_at, created_at FROM projects WHERE user_id = ? ORDER BY id');
            $q->execute([$userId]);
            foreach ($q->fetchAll() as $project) {
                foreach (['project_files' => 'id, path, language, file_size, line_count, role_name, plain_summary', 'scan_runs' => 'id, commit_sha, analysis_profile, files_scanned, findings_count, created_at', 'scan_findings' => 'id, severity, finding_type, title, plain_explanation, file_path'] as $table => $columns) {
                    $rows = $pdo->prepare("SELECT $columns FROM $table WHERE project_id = ? ORDER BY id LIMIT 10001");
                    $rows->execute([$project['id']]);
                    $data = $rows->fetchAll();
                    $project[$table] = array_slice($data, 0, 10000);
                    $project[$table . '_truncated'] = count($data) > 10000;
                }
                $result['projects'][] = $project;
            }
            $q = $pdo->prepare('SELECT category, area, usefulness, created_at FROM pilot_feedback WHERE user_id = ? ORDER BY id');
            $q->execute([$userId]);
            $result['feedback'] = $q->fetchAll();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            } throw $e;
        }
    }

    public static function delete(int $userId, string $password, string $confirmation): bool
    {
        if ($confirmation !== 'DELETE' || strlen($password) > 72) {
            return false;
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT email, password_hash FROM users WHERE id = ? FOR UPDATE');
            $q->execute([$userId]);
            $user = $q->fetch();
            if (!$user || !password_verify($password, $user['password_hash'])) {
                $pdo->rollBack();
                return false;
            }
            $pdo->prepare('INSERT INTO storage_cleanup_tasks(project_id) SELECT id FROM projects WHERE user_id = ? ON CONFLICT DO NOTHING')->execute([$userId]);
            $pdo->prepare('DELETE FROM account_link_requests WHERE LOWER(email) = ?')->execute([strtolower($user['email'])]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            } throw $e;
        }
        // The durable tombstone survives filesystem errors and workers finishing late.
        try {
            PilotOperations::clearLegacyProviderCache();
            PilotOperations::cleanup();
        } catch (Throwable) {
            \Logger::warning('Account storage cleanup pending');
        }
        return true;
    }
}
