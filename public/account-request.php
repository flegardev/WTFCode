<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
$purpose = ($_GET['purpose'] ?? '') === 'reset' ? 'reset' : 'verify';
$sent = false;
if (is_post()) {
    verify_csrf();
    \WTFCode\Application\AccountLifecycle::requestLink(post_string('email'), $purpose);
    $sent = true;
}
$pageTitle = $purpose === 'reset' ? 'Recover your account' : 'Verify your email';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow"><section class="auth-card"><h1><?= e($pageTitle) ?></h1><p><?= $purpose === 'reset' ? 'Request a single-use link to choose a new password. Resetting signs out your existing sessions.' : 'Open your verification message, then confirm the link to access the pilot.' ?></p>
<?php if ($sent): ?><p role="status">If this address has an eligible account, a message will be sent. Check your inbox and spam folder. You can try again later.</p><?php endif; ?>
<form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Email<input name="email" type="email" maxlength="190" autocomplete="email" required></label><button class="button button-primary">Request <?= $purpose === 'reset' ? 'recovery' : 'verification' ?> link</button></form><p><a href="<?= e(url('login.php')) ?>">Back to sign in</a></p></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>
