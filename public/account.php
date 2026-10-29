<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
Auth::requireAccount();
$error = null;
if (is_post()) {
    verify_csrf();
    if (post_string('action') === 'export') {
        $export = \WTFCode\Application\AccountData::export((int) Auth::id());
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="wtfcode-account.json"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR); exit;
    }
    if (post_string('action') === 'delete') {
        $key = LoginRateLimiter::key('account-delete:' . Auth::id());
        if (!LoginRateLimiter::blocked($key)) {
            LoginRateLimiter::failed($key);
            if (\WTFCode\Application\AccountData::delete((int) Auth::id(), post_string('password'), post_string('confirmation'))) { Auth::logout(); redirect('login.php?account_deleted=1'); }
        }
        $error = 'Deletion was not confirmed. Check your current password and type DELETE. After repeated attempts, wait 15 minutes.';
    }
}
$pageTitle = 'Account and data'; $activePage = 'settings';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow"><h1>Account and data</h1><p>Your account: <?= e((string) Auth::user()['email']) ?></p>
<section class="overview-section"><h2>Export your data</h2><p>Download account, project, file and scan metadata, findings and optional feedback. This is not a full source-code archive. Large exports identify any truncated sections.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button name="action" value="export" class="button button-secondary">Download account data</button></form></section>
<section class="overview-section"><h2>Delete your account</h2><p>This permanently removes your account, projects, stored evidence, integration links and jobs. Sessions are revoked. Temporary files are scheduled for cleanup, including files from interrupted jobs. Existing encrypted backups may retain deleted records until their documented retention period ends; they must not be restored into active service without reapplying deletions.</p><?php if ($error): ?><p class="inline-error" role="alert"><?= e($error) ?></p><?php endif; ?><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Current password<input name="password" type="password" autocomplete="current-password" maxlength="72" required></label><label>Type DELETE to confirm<input name="confirmation" autocomplete="off" required></label><button class="button button-secondary" name="action" value="delete">Permanently delete my account</button></form></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>
