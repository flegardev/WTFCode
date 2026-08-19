<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$pageTitle = 'Privacy';
require __DIR__ . '/../views/header.php';
?>
<section class="legal-shell"><p class="landing-kicker">Privacy</p><h1>Your repository remains your repository.</h1><p>WTFCode stores your account, public repository URL, project metadata, derived dependency information, detected symbol names, learning progress, and scan findings. Source file contents are analysed in transient memory during a scan and are not stored in the application database.</p><p>Repository clones are kept in private application storage so Git analysis and rescans can work. This MVP has no external AI integration and does not send repository source or prompts to an AI provider.</p><p>For a production launch, add an account deletion workflow, retention policy, data export, a legal contact, and jurisdiction-specific privacy terms.</p></section>
<?php require __DIR__ . '/../views/footer.php'; ?>
