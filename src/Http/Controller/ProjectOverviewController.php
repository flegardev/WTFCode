<?php

declare(strict_types=1);

namespace WTFCode\Http\Controller;

use WTFCode\Application\ProjectOverviewService;

final class ProjectOverviewController
{
    public function __construct(private readonly ProjectOverviewService $overview = new ProjectOverviewService()) {}

    /** @return array<string, mixed>|null */
    public function show(int $userId, int $projectId): ?array
    {
        return $this->overview->forUser($projectId, $userId);
    }
}
