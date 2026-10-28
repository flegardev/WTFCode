<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$usage = Entitlements::usage((int) Auth::id());
$pageTitle = 'Settings';
$activePage = 'settings';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow settings-page">
    <div class="app-heading"><div><p class="eyebrow">Your workspace</p><h1>Settings</h1><p>Preferences, current usage and how your code is handled.</p></div></div>
    <section class="overview-section"><h2>Appearance</h2><p>Use the Light / Dark control in the header to change the theme. Your choice is saved in this browser.</p></section>
    <section class="overview-section"><h2>Your public repository trial</h2><p><?= $usage['projects'] ?> of <?= $usage['limits']['projects'] ?> projects · <?= $usage['scans'] ?> of <?= $usage['limits']['scans'] ?> scans in the last 30 days.</p><p>Questions use the last scan and run locally. No payment details are collected.</p><a href="<?= e(url('pricing.php')) ?>">Read about proposed plans →</a></section>
    <section class="overview-section"><h2>Your source and privacy</h2><p>Repository code is read without being executed. Temporary checkouts are removed after each scan; bounded evidence can be stored. Private imports remain unavailable until verified for release.</p><a href="<?= e(url('privacy.php')) ?>">Privacy details</a> · <a href="<?= e(url('security.php')) ?>">Security details</a></section>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>
