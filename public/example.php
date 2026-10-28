<?php
declare(strict_types=1);
if (!isset($_COOKIE['wtfcode_session'])) define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
$pageTitle = 'Explore a real public example';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell"><div class="app-heading"><div><p class="eyebrow">Try the experience</p><h1>Clarity, with the evidence attached.</h1><p>Explore saved results from a real public repository before bringing your own.</p></div></div><?php require __DIR__ . '/../views/public-example.php'; ?></section>
<script src="<?= e(url('assets/js/public-example.js')) ?>" defer></script>
<?php require __DIR__ . '/../views/footer.php'; ?>
