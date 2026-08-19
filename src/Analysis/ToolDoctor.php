<?php

declare(strict_types=1);

final class ToolDoctor
{
    public function __construct(private readonly SafeProcessRunner $runner = new SafeProcessRunner())
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function inspect(string $workingDirectory): array
    {
        $checks = [
            ['name' => 'PHP', 'candidates' => [PHP_BINARY], 'args' => ['--version'], 'required' => true],
            ['name' => 'Git', 'candidates' => ['git'], 'args' => ['--version'], 'required' => true],
            ['name' => 'Node', 'candidates' => ['node'], 'args' => ['--version'], 'required' => false],
            ['name' => 'Tree-sitter', 'candidates' => ['tree-sitter'], 'args' => ['--version'], 'required' => false],
            ['name' => 'ast-grep', 'candidates' => ['ast-grep', 'sg'], 'args' => ['--version'], 'required' => false],
            ['name' => 'Semgrep', 'candidates' => ['semgrep'], 'args' => ['--version'], 'required' => false],
            ['name' => 'ctags', 'candidates' => ['ctags'], 'args' => ['--version'], 'required' => false],
            ['name' => 'Gitleaks', 'candidates' => ['gitleaks'], 'args' => ['version'], 'required' => false],
            ['name' => 'OSV', 'candidates' => ['osv-scanner'], 'args' => ['--version'], 'required' => false],
            ['name' => 'ripgrep', 'candidates' => ['rg'], 'args' => ['--version'], 'required' => false],
            ['name' => 'Syft', 'candidates' => ['syft'], 'args' => ['version'], 'required' => false],
            ['name' => 'Grype', 'candidates' => ['grype'], 'args' => ['version'], 'required' => false],
        ];

        $results = [$this->mysqlCheck()];
        foreach ($checks as $check) $results[] = $this->commandCheck($check, $workingDirectory);
        usort($results, static function (array $left, array $right): int {
            $order = ['PHP', 'MySQL', 'Git', 'Node', 'Tree-sitter', 'ast-grep', 'Semgrep', 'ctags', 'Gitleaks', 'OSV', 'ripgrep', 'Syft', 'Grype'];
            return array_search($left['name'], $order, true) <=> array_search($right['name'], $order, true);
        });
        return $results;
    }

    /** @param array{name: string, candidates: array<int, string>, args: array<int, string>, required: bool} $check @return array<string, mixed> */
    private function commandCheck(array $check, string $workingDirectory): array
    {
        $executable = null;
        foreach ($check['candidates'] as $candidate) {
            $executable = ToolDetector::findExecutable($candidate);
            if ($executable !== null) break;
        }
        if ($executable === null) {
            return [
                'name' => $check['name'],
                'status' => $check['required'] ? 'Missing' : 'Optional',
                'version' => null,
                'path' => null,
                'message' => $check['required'] ? 'Required executable was not found.' : 'Not installed; basic scans continue without it.',
            ];
        }
        try {
            $result = $this->runner->run(new ProcessRunRequest([$executable, ...$check['args']], $workingDirectory, 10, 131072, 131072));
            $output = trim($result->stdout !== '' ? $result->stdout : $result->stderr);
            $version = trim((string) (preg_split('/\R/', $output)[0] ?? ''));
            return [
                'name' => $check['name'],
                'status' => $result->succeeded() ? 'Ready' : 'Unsupported',
                'version' => $version === '' ? null : substr($version, 0, 200),
                'path' => $executable,
                'message' => $result->succeeded() ? 'Version probe succeeded.' : ($result->timedOut ? 'Version probe timed out.' : 'Executable was found but its version probe failed.'),
            ];
        } catch (Throwable $exception) {
            return ['name' => $check['name'], 'status' => 'Unsupported', 'version' => null, 'path' => $executable, 'message' => $exception->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function mysqlCheck(): array
    {
        try {
            $version = (string) Database::connection()->query('SELECT VERSION()')->fetchColumn();
            return ['name' => 'MySQL', 'status' => 'Ready', 'version' => $version, 'path' => null, 'message' => 'Database connection succeeded through PDO.'];
        } catch (Throwable $exception) {
            return ['name' => 'MySQL', 'status' => 'Missing', 'version' => null, 'path' => null, 'message' => 'Database connection failed: ' . $exception->getMessage()];
        }
    }
}
