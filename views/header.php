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
<body class="<?= $currentUser === null ? 'public-site' : 'workspace-site' ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="site-header">
    <div class="nav-shell">
        <a class="brand" href="<?= e(url($currentUser === null ? 'index.php' : 'dashboard.php')) ?>"><span class="brand-mark">W</span>WTFCode</a>
        <nav class="top-nav" aria-label="Primary navigation">
            <?php if ($currentUser === null): ?>
                <a class="marketing-nav" href="<?= e(url('index.php#how-it-works')) ?>">Features</a>
                <a class="marketing-nav" href="<?= e(url('pricing.php')) ?>">Pricing</a>
                <a href="<?= e(url('login.php')) ?>">Sign in</a>
                <a class="button button-primary button-small" href="<?= e(url('register.php')) ?>">Analyze your project</a>
            <?php else: ?>
                <span class="user-name"><?= e($currentUser['name']) ?></span>
                <form action="<?= e(url('logout.php')) ?>" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="text-button" type="submit">Log out</button></form>
            <?php endif; ?>
        </nav>
        <button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to light theme">Light</button>
    </div>
</header>
<?php if ($currentUser !== null): ?><div class="product-layout"><aside class="product-sidebar" aria-label="Workspace"><p class="sidebar-label">Workspace</p><nav><a class="<?= $activePage === 'dashboard' ? 'is-active' : '' ?>" href="<?= e(url('dashboard.php')) ?>"><?= ui_icon('folder-git-2') ?>Projects</a><a class="<?= $activePage === 'import' ? 'is-active' : '' ?>" href="<?= e(url('project-create.php')) ?>"><?= ui_icon('plus') ?>Add project <span aria-hidden="true">+</span></a><a href="<?= e(url('settings.php')) ?>"<?= $activePage === 'settings' ? ' aria-current="page"' : '' ?>><?= ui_icon('settings') ?>Settings</a><?php if ($isAdmin): ?><a href="<?= e(url('admin.php')) ?>">Admin</a><?php endif; ?></nav><div class="sidebar-bottom"><span class="plan-badge">Public repository trial</span><p>Clarity before your next change.</p><a href="<?= e(url('pricing.php')) ?>">View proposed plans →</a></div></aside><div class="product-main"><?php endif; ?>
<main id="main-content" tabindex="-1">
    <?php if ($message = flash('success')): ?><div class="notice notice-success" role="status"><div><?= e($message) ?></div></div><?php endif; ?>
    <?php if ($message = flash('error')): ?><div class="notice notice-error" role="alert"><div><?= e($message) ?></div></div><?php endif; ?>
