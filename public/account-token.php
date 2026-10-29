<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$purpose = ($_GET['purpose'] ?? '') === 'reset' ? 'reset' : 'verify';
$error = null;
if (is_post()) {
    verify_csrf();
    $password = post_string('password');
    if ($purpose === 'reset' && $password !== post_string('password_confirmation')) $error = 'Passwords must match.';
    elseif (\WTFCode\Application\AccountLifecycle::consume(post_string('token'), $purpose, $password)) {
        if (Auth::check()) Auth::logout();
        redirect('login.php?account_updated=1');
    } else $error = 'This link is invalid, expired or already used. Request a new link. Passwords must be between 10 and 72 bytes.';
}
$pageTitle = $purpose === 'reset' ? 'Choose a new password' : 'Confirm your email';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow"><section class="auth-card"><h1><?= e($pageTitle) ?></h1><p>Confirm below to use this single-use link.</p><?php if ($error): ?><p class="inline-error" role="alert" data-account-token-error><?= e($error) ?></p><?php endif; ?><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label id="account-token-label">Link code<input id="account-token" name="token" autocomplete="off" pattern="[a-f0-9]{64}" maxlength="64" required></label><p id="account-token-help">The code is filled from your link. If needed, paste the 64-character code after #token= from the message.</p><?php if ($purpose === 'reset'): ?><label>New password<input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label><label>Confirm password<input name="password_confirmation" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label><?php endif; ?><button class="button button-primary"><?= $purpose === 'reset' ? 'Reset password' : 'Verify email' ?></button></form><p><a href="<?= e(url('account-request.php?purpose=' . $purpose)) ?>">Request a new link</a></p></section></section>
<script src="<?= e(url('assets/account-token.js')) ?>" defer></script>
<?php require __DIR__ . '/../views/footer.php'; ?>
