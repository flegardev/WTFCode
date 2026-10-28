<?php

declare(strict_types=1);

if (!isset($_COOKIE['wtfcode_session'])) define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
$pageTitle = 'Privacy';
require __DIR__ . '/../views/header.php';
?>
<section class="legal-shell"><p class="landing-kicker">Privacy</p><h1>Your repository remains your repository.</h1><p>WTFCode stores your account, repository URL, project metadata, derived dependency information, detected symbol names and signatures, bounded relationship evidence excerpts, exploration progress, and scan findings. Complete source files are not retained in the application database. Derived evidence can still be sensitive.</p><p>In production, each scan uses a temporary checkout, removed after the worker finishes. A source or Git view may create a temporary checkout removed after the request. Local development retains checkouts for debugging. Questions use the local deterministic engine; customer questions do not invoke external AI providers. Private repository access is disabled by default pending verification.</p><h2>Before public launch</h2><p>This staging MVP still needs an operator identity, legal contact, retention schedule, and tested account export/deletion process. Do not import confidential source until those controls and private access have been reviewed.</p></section>
<?php require __DIR__ . '/../views/footer.php'; ?>
