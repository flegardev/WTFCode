<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
if (Auth::check()) redirect('dashboard.php');

$errors = [];
$old = ['name' => '', 'email' => ''];
if (is_post()) {
    verify_csrf();
    $old = ['name' => post_string('name'), 'email' => post_string('email')];
    $errors = Auth::register($old['name'], $old['email'], (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirmation'] ?? ''));
    if ($errors === []) {
        flash('success', 'Your workspace is ready. Import a public GitHub repository to begin.');
        redirect('dashboard.php');
    }
}
$pageTitle = 'Create your workspace';
require __DIR__ . '/../views/header.php';
?>
<section class="auth-shell"><div class="auth-aside"><p class="landing-kicker">Repository clarity</p><h1>Understand the code you just created.</h1><p>Start with a repository. Get the map before you make the next AI-assisted change.</p></div><section class="auth-card"><h2>Create your workspace</h2><form method="post" class="form-stack" novalidate><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Name<input type="text" name="name" value="<?= e($old['name']) ?>" maxlength="100" autocomplete="name" required><?php if (isset($errors['name'])): ?><small><?= e($errors['name']) ?></small><?php endif; ?></label><label>Email<input type="email" name="email" value="<?= e($old['email']) ?>" maxlength="190" autocomplete="email" required><?php if (isset($errors['email'])): ?><small><?= e($errors['email']) ?></small><?php endif; ?></label><label>Password<input type="password" name="password" minlength="10" autocomplete="new-password" required><?php if (isset($errors['password'])): ?><small><?= e($errors['password']) ?></small><?php endif; ?></label><label>Confirm password<input type="password" name="password_confirmation" minlength="10" autocomplete="new-password" required><?php if (isset($errors['confirmation'])): ?><small><?= e($errors['confirmation']) ?></small><?php endif; ?></label><button class="button button-primary" type="submit">Create workspace</button></form><p class="auth-switch">Already registered? <a href="<?= e(url('login.php')) ?>">Log in</a></p></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>
