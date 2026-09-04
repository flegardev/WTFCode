<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use WTFCode\Repository\AdminRepository;

Auth::requireAdmin();
$admin = new AdminRepository();
$actorId = (int) Auth::id();
$query = post_string('search');
if (!is_post()) {
    $query = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
}

if (is_post()) {
    verify_csrf();
    $action = post_string('action');
    try {
        switch ($action) {
            case 'make_admin':
            case 'remove_admin':
            case 'suspend':
            case 'restore':
                $admin->setUserFlags($actorId, (int) ($_POST['user_id'] ?? 0), $action);
                flash('success', 'User access was updated.');
                break;
            case 'cancel_job':
                $admin->cancelJob($actorId, (int) ($_POST['job_id'] ?? 0));
                flash('success', 'The scan job was cancelled.');
                break;
            case 'delete_project':
                $admin->deleteProject($actorId, (int) ($_POST['project_id'] ?? 0));
                flash('success', 'The project and its derived evidence were deleted.');
                break;
            default:
                throw new RuntimeException('That administrator action is not available.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The administrator action could not be completed.');
        Logger::error('Administrator action failed', ['action' => $action, 'actor_id' => $actorId, 'type' => get_class($exception)]);
    }
    redirect('admin.php' . ($query !== '' ? '?q=' . rawurlencode($query) : ''));
}

$summary = $admin->summary();
$users = $admin->users($query);
$projects = $admin->projects($query);
$jobs = $admin->jobs();
$flag = static fn (mixed $value): bool => $value === true || $value === 1 || $value === '1' || strtolower((string) $value) === 't' || strtolower((string) $value) === 'true';
$pageTitle = 'Admin control';
$activePage = 'admin';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell admin-shell">
    <div class="app-heading">
        <div><p class="landing-kicker">Operator console</p><h1>Control the workspace.</h1><p>Manage accounts, projects, and stuck scan jobs from one protected surface. Every write is authenticated, CSRF-checked, and logged.</p></div>
        <a class="button button-secondary" href="<?= e(url('dashboard.php')) ?>">Back to projects</a>
    </div>

    <section class="metric-grid admin-metrics">
        <article><span>Users</span><strong><?= (int) $summary['users'] ?></strong><p>Registered workspaces</p></article>
        <article><span>Projects</span><strong><?= (int) $summary['projects'] ?></strong><p>Across every account</p></article>
        <article><span>Queued</span><strong><?= (int) $summary['queued_jobs'] ?></strong><p>Waiting for workers</p></article>
        <article><span>Running</span><strong><?= (int) $summary['running_jobs'] ?></strong><p>Currently leased</p></article>
        <article><span>Review items</span><strong><?= (int) $summary['findings'] ?></strong><p>Attention and risk findings</p></article>
    </section>

    <form class="admin-filter panel" method="get">
        <label for="admin-search">Filter users and projects</label>
        <div><input id="admin-search" type="search" name="q" value="<?= e($query) ?>" placeholder="Name, email, project, or repository"><button class="button button-secondary" type="submit">Filter</button><?php if ($query !== ''): ?><a class="button button-ghost" href="<?= e(url('admin.php')) ?>">Clear</a><?php endif; ?></div>
    </form>

    <section class="panel admin-panel">
        <div class="section-row"><div><p class="landing-kicker">Accounts</p><h2>Users and access</h2><p>Suspended accounts cannot log in. Keep at least one active administrator at all times.</p></div></div>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Projects</th><th>State</th><th>Access</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($users as $user): ?>
            <?php $isAdmin = $flag($user['is_admin'] ?? false); $isSuspended = $flag($user['is_suspended'] ?? false); $isSelf = (int) $user['id'] === $actorId; ?>
            <tr><td><strong><?= e($user['name']) ?></strong><small><?= e($user['email']) ?></small><small>Joined <?= e(substr((string) $user['created_at'], 0, 10)) ?></small></td><td><?= (int) $user['project_count'] ?></td><td><span class="admin-badge <?= $isSuspended ? 'is-danger' : 'is-good' ?>"><?= $isSuspended ? 'Suspended' : 'Active' ?></span></td><td><span class="admin-badge <?= $isAdmin ? 'is-accent' : '' ?>"><?= $isAdmin ? 'Administrator' : 'Member' ?></span></td><td><div class="admin-actions">
                <?php if ($isAdmin): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove_admin"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><button class="button button-ghost" type="submit" <?= $isSelf ? 'disabled title="You cannot remove your own access"' : '' ?>>Remove admin</button></form><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="make_admin"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><button class="button button-ghost" type="submit">Make admin</button></form><?php endif; ?>
                <?php if ($isSuspended): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><button class="button button-ghost" type="submit">Restore</button></form><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="suspend"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><button class="button button-ghost" type="submit" <?= $isSelf ? 'disabled title="You cannot suspend your own access"' : '' ?>>Suspend</button></form><?php endif; ?>
            </div></td></tr>
        <?php endforeach; ?>
        <?php if ($users === []): ?><tr><td colspan="5" class="muted-copy">No users match this filter.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="panel admin-panel">
        <div class="section-row"><div><p class="landing-kicker">Repositories</p><h2>Projects across the workspace</h2><p>Open any project for evidence review, or remove its persisted metadata and derived scan data.</p></div></div>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Project</th><th>Owner</th><th>Status</th><th>Evidence</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($projects as $project): ?>
            <tr><td><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><strong><?= e($project['name']) ?></strong></a><small><?= e($project['repository_url']) ?></small></td><td><strong><?= e($project['user_name']) ?></strong><small><?= e($project['user_email']) ?></small></td><td><span class="admin-badge <?= $project['status'] === 'ready' ? 'is-good' : ($project['status'] === 'failed' ? 'is-danger' : '') ?>"><?= e($project['status']) ?></span><?php if ($project['latest_job_state']): ?><small>job: <?= e($project['latest_job_state']) ?></small><?php endif; ?></td><td><small><?= (int) $project['file_count'] ?> files</small><small><?= (int) $project['finding_count'] ?> review items</small></td><td><form method="post" data-confirm="Delete this project and all of its derived evidence? This cannot be undone."><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>"><button class="button button-danger" type="submit">Delete project</button></form></td></tr>
        <?php endforeach; ?>
        <?php if ($projects === []): ?><tr><td colspan="5" class="muted-copy">No projects match this filter.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="panel admin-panel">
        <div class="section-row"><div><p class="landing-kicker">Queue operations</p><h2>Scan jobs</h2><p>Cancel queued or running work when a worker is stuck. Previous evidence remains available when a project has already completed a scan.</p></div></div>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Job</th><th>Project / owner</th><th>State</th><th>Attempts</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($jobs as $job): ?>
            <tr><td><strong>#<?= (int) $job['id'] ?></strong><small><?= e($job['job_kind']) ?> · <?= e($job['analysis_profile']) ?></small><small><?= e($job['current_stage']) ?></small></td><td><strong><?= e($job['project_name']) ?></strong><small><?= e($job['user_email']) ?></small></td><td><span class="admin-badge <?= $job['state'] === 'failed' ? 'is-danger' : ($job['state'] === 'completed' ? 'is-good' : '') ?>"><?= e($job['state']) ?></span><?php if ($job['error_message']): ?><small><?= e($job['error_message']) ?></small><?php endif; ?></td><td><?= (int) $job['attempt_count'] ?> / <?= (int) $job['max_attempts'] ?></td><td><?php if (in_array($job['state'], ['queued', 'running'], true)): ?><form method="post" data-confirm="Cancel this scan job?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><button class="button button-danger" type="submit">Cancel job</button></form><?php else: ?><span class="muted-copy">No action</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if ($jobs === []): ?><tr><td colspan="5" class="muted-copy">No scan jobs yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</section>
<script src="<?= e(url('assets/js/admin-console.js')) ?>" defer></script>
<?php require __DIR__ . '/../views/footer.php'; ?>
