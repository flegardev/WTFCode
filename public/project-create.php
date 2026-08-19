<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$error = null;
$old = ['name' => '', 'repository_url' => ''];
if (is_post()) {
    verify_csrf();
    $old = ['name' => post_string('name'), 'repository_url' => post_string('repository_url')];
    $result = Project::createFromGithub(Auth::id(), $old['name'], $old['repository_url']);
    if (isset($result['error'])) { $error = $result['error']; } else { flash('success', 'Repository imported and analysed.'); redirect('project.php?id=' . (int) $result['project']['id']); }
}
$pageTitle = 'Import repository';
$activePage = 'import';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow"><div class="app-heading compact"><div><p class="landing-kicker">New repository</p><h1>Import your project.</h1><p>WTFCode currently supports public GitHub repositories. Private repository OAuth belongs in a future, credentialed integration.</p></div></div><section class="import-card"><h2>Public GitHub repository</h2><?php if ($error !== null): ?><div class="inline-error"><?= e($error) ?></div><?php endif; ?><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Project name <span>Optional</span><input name="name" type="text" maxlength="140" value="<?= e($old['name']) ?>" placeholder="My app"></label><label>GitHub repository URL<input name="repository_url" type="url" value="<?= e($old['repository_url']) ?>" placeholder="https://github.com/owner/repository" required></label><button class="button button-primary" type="submit">Import and analyse</button></form><div class="import-safety"><strong>What happens next</strong><p>WTFCode validates the GitHub host, clones the public repository to private application storage, scans file metadata and relationships, then keeps source contents out of its database.</p></div></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>

