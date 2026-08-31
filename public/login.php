<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
if (Auth::check()) redirect('dashboard.php');

$email = '';
$error = null;
if (is_post()) {
    verify_csrf();
    $email = post_string('email');
    if (Auth::attempt($email, (string) ($_POST['password'] ?? ''))) { redirect('dashboard.php'); }
    $error = 'Those login details do not match an account.';
}
$pageTitle = 'Log in';
require __DIR__ . '/../views/header.php';
?>
<section class="auth-shell"><div class="auth-aside"><p class="landing-kicker">Welcome back</p><h1>See your codebase with fresh eyes.</h1><p>Architecture, dependencies, and the files worth understanding before your next change.</p></div><section class="auth-card"><h2>Log in</h2><?php if ($error !== null): ?><div class="inline-error"><?= e($error) ?></div><?php endif; ?><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Email<input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="button button-primary" type="submit">Log in</button></form><p class="auth-switch">No workspace yet? <a href="<?= e(url('register.php')) ?>">Create one</a></p></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>

