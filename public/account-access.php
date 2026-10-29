<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
Auth::requireAccount();
if (Auth::workspaceAllowed()) redirect('dashboard.php');
if (is_post()) { verify_csrf(); \WTFCode\Application\AccountLifecycle::requestLink((string) Auth::user()['email'], 'verify'); flash('success', 'If eligible, a verification message will be sent.'); redirect('account-access.php'); }
$pageTitle = 'Your pilot access';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow"><h1>Your pilot access</h1><p>Workspace access requires a verified email and an approved pilot address. If you have verified your address and access is still closed, contact the founder through your agreed pilot channel.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="button button-primary">Request verification email</button></form><p><a href="<?= e(url('dashboard.php')) ?>">Check workspace access</a> · <a href="<?= e(url('account.php')) ?>">Export or delete your account</a></p></section>
<?php require __DIR__ . '/../views/footer.php'; ?>
