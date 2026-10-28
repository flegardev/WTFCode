<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'WTFCode';
$activePage = $activePage ?? '';
$currentUser = Auth::user();
$projectContext = $currentUser !== null && isset($project) && is_array($project) && isset($project['id']);
$isAdmin = $currentUser !== null && Auth::isAdmin();
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#080c0e">
    <title><?= e($pageTitle) ?> | WTFCode</title>
    <meta name="description" content="Understand what your AI built before you change it.">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/saas.css')) ?>">
    <script src="<?= e(url('assets/js/theme.js')) ?>"></script>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="site-header">
    <div class="nav-shell">
        <a class="brand" href="<?= e(url($currentUser === null ? 'index.php' : 'dashboard.php')) ?>"><span class="brand-mark">W</span>WTFCode</a>
        <nav class="top-nav" aria-label="Primary navigation">
            <?php if ($currentUser === null): ?>
                <a class="marketing-nav" href="<?= e(url('index.php#how-it-works')) ?>">Features</a>
                <a class="marketing-nav" href="<?= e(url('pricing.php')) ?>">Pricing</a>
                <a href="<?= e(url('login.php')) ?>">Sign in</a>
                <a class="button button-primary button-small" href="<?= e(url('register.php')) ?>">Get started</a>
            <?php else: ?>
                <a class="<?= $activePage === 'dashboard' ? 'is-active' : '' ?>" href="<?= e(url('dashboard.php')) ?>"<?= $activePage === 'dashboard' && !$projectContext ? ' aria-current="page"' : '' ?>>Projects</a>
                <a class="<?= $activePage === 'import' ? 'is-active' : '' ?>" href="<?= e(url('project-create.php')) ?>"<?= $activePage === 'import' ? ' aria-current="page"' : '' ?>>Import repository</a>
                <?php if ($isAdmin): ?><a class="<?= $activePage === 'admin' ? 'is-active' : '' ?>" href="<?= e(url('admin.php')) ?>"<?= $activePage === 'admin' ? ' aria-current="page"' : '' ?>>Admin</a><?php endif; ?>
                <span class="user-name"><?= e($currentUser['name']) ?></span>
                <form action="<?= e(url('logout.php')) ?>" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="text-button" type="submit">Log out</button></form>
            <?php endif; ?>
        </nav>
        <button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to light theme">Light</button>
    </div>
</header>
<main id="main-content" tabindex="-1">
    <?php if ($message = flash('success')): ?><div class="notice notice-success" role="status"><div><?= e($message) ?></div></div><?php endif; ?>
    <?php if ($message = flash('error')): ?><div class="notice notice-error" role="alert"><div><?= e($message) ?></div></div><?php endif; ?>
