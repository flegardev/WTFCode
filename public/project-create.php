<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$error = null;
$old = ['name' => '', 'repository_url' => '', 'repository_choice' => '', 'profile' => AnalysisProfile::QUICK];
if (is_post()) {
    verify_csrf();
    $old = ['name' => post_string('name'), 'repository_url' => post_string('repository_url'), 'repository_choice' => post_string('repository_choice'), 'profile' => AnalysisProfile::normalize(post_string('profile'))];
    $userId = Auth::id();
    release_session_lock();
    if (post_string('import_type') === 'github_app') {
        $parts = explode(':', $old['repository_choice'], 2);
        $installationId = filter_var($parts[0] ?? null, FILTER_VALIDATE_INT);
        $repositoryId = filter_var($parts[1] ?? null, FILTER_VALIDATE_INT);
        $result = $installationId && $repositoryId
            ? Project::queueFromGitHubInstallation($userId, (int) $installationId, (int) $repositoryId, $old['name'], $old['profile'])
            : ['error' => 'Choose a repository from the GitHub picker.'];
    } else {
        $result = Project::queueFromGithub($userId, $old['name'], $old['repository_url'], $old['profile']);
    }
    resume_session();
    if (isset($result['error'])) {
        $error = $result['error'];
    } else {
        flash('success', 'Repository queued for analysis. You can keep browsing while the worker runs.');
        redirect('project.php?id=' . (int) $result['project']['id']);
    }
}
$installations = GitHubInstallationRepository::allForUser((int) Auth::id());
$repositoryOptions = [];
$connectionErrors = [];
foreach (GitHubAppService::configured() ? $installations : [] as $installation) {
    try {
        foreach (GitHubAppService::repositoriesForUser((int) Auth::id(), (int) $installation['id']) as $repository) {
            $repositoryOptions[] = ['installation' => $installation, 'repository' => $repository];
        }
    } catch (GitHubAccessException $exception) {
        $connectionErrors[(int) $installation['id']] = $exception->safeMessage();
    }
}
$pageTitle = 'Import repository';
$activePage = 'import';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow">
    <div class="app-heading compact"><div><p class="landing-kicker">Let's make sense of your code</p><h1>Connect your repository.</h1><p>Start with a public GitHub URL. No GitHub authorization or payment details needed.</p></div></div>
    <ol class="onboarding-steps" aria-label="Analysis workflow"><li aria-current="step"><b>1</b> Select repository</li><li><b>2</b> Analyze codebase</li><li><b>3</b> Explore insights</li></ol>
    <?php if ($error !== null): ?><div class="inline-error"><?= e($error) ?></div><?php endif; ?>
    <details class="import-card private-import">
        <summary>Private or organization repositories</summary>
        <h2>Private or organization repository</h2>
        <p>Installation access is repository-scoped and read-only. WTFCode creates a short-lived token for each operation and revokes it after use.</p>
        <?php if (GitHubAppService::configured()): ?>
            <form method="post" action="<?= e(url('github-connect.php')) ?>" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="button button-secondary" type="submit"><?= $installations === [] ? 'Connect GitHub' : 'Add or update GitHub access' ?></button>
            </form>
        <?php else: ?><p class="muted-copy">Private repository access is not available on this deployment. It stays disabled until authorization, isolation, and retention have been verified.</p><?php endif; ?>
        <?php foreach ($connectionErrors as $connectionError): ?><div class="inline-error"><?= e($connectionError) ?></div><?php endforeach; ?>
        <?php if ($repositoryOptions !== []): ?>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="import_type" value="github_app">
                <label>Project name <span>Optional</span><input name="name" type="text" maxlength="140" value="<?= e($old['name']) ?>" placeholder="My app"></label>
                <label>Find repository<input type="search" data-repository-search placeholder="Search owner or repository" autocomplete="off"></label>
                <label>Accessible repository<select name="repository_choice" data-repository-select required><option value="">Choose a repository</option><?php foreach ($repositoryOptions as $option): ?><?php $repository = $option['repository']; $installation = $option['installation']; $value = (int) $installation['id'] . ':' . (int) $repository['id']; ?><option value="<?= e($value) ?>" <?= $old['repository_choice'] === $value ? 'selected' : '' ?>><?= e($repository['full_name']) ?> · <?= e($repository['visibility']) ?></option><?php endforeach; ?></select></label>
                <details class="import-advanced"><summary>Advanced analysis options</summary><p>Quick is recommended. Deeper profiles may take longer and require additional analyzers.</p><label>Analysis profile<select name="profile"><?php foreach ((\WTFCode\Application\PilotAccess::mode() === 'allowlist' ? [AnalysisProfile::QUICK => 'Quick'] : [AnalysisProfile::QUICK => 'Quick', AnalysisProfile::DEEP => 'Deep', AnalysisProfile::SECURITY => 'Security', AnalysisProfile::MAXIMUM => 'Maximum']) as $value => $label): ?><option value="<?= e($value) ?>" <?= $old['profile'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label></details>
                <button class="button button-primary" type="submit">Queue analysis</button>
            </form>
        <?php elseif ($installations !== []): ?><p class="muted-copy">No repositories are currently granted. Update the GitHub App installation and select at least one repository.</p><?php endif; ?>
    </details>
    <section class="import-card">
        <h2>Bring the app you're building.</h2>
        <p>We read your source to build a map. We never run it or install its dependencies.</p>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="import_type" value="public">
            <label>Project name <span>Optional</span><input name="name" type="text" maxlength="140" value="<?= e($old['name']) ?>" placeholder="My app"></label>
            <label>GitHub repository URL<input name="repository_url" type="url" value="<?= e($old['repository_url']) ?>" placeholder="https://github.com/owner/repository" required></label>
            <details class="import-advanced"><summary>Advanced analysis options</summary><p>Quick is recommended. Deeper profiles may take longer and require additional analyzers.</p><label>Analysis profile<select name="profile"><?php foreach ((\WTFCode\Application\PilotAccess::mode() === 'allowlist' ? [AnalysisProfile::QUICK => 'Quick'] : [AnalysisProfile::QUICK => 'Quick', AnalysisProfile::DEEP => 'Deep', AnalysisProfile::SECURITY => 'Security', AnalysisProfile::MAXIMUM => 'Maximum']) as $value => $label): ?><option value="<?= e($value) ?>" <?= $old['profile'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label></details>
            <button class="button button-primary" type="submit">Analyze repository <span aria-hidden="true">→</span></button>
        </form>
        <div class="import-safety"><strong>What happens next</strong><p>Your scan runs in the background. You can follow its progress, then explore the architecture, ask questions, and trace dependencies. Free trial: one public project and five scans per rolling 30 days. Repositories are limited to 100 MB with up to 20 MB of readable source.</p></div>
    </section>
</section>
<?php if ($repositoryOptions !== []): ?><script src="<?= e(url('assets/js/repository-picker.js')) ?>" defer></script><?php endif; ?>
<?php require __DIR__ . '/../views/footer.php'; ?>
