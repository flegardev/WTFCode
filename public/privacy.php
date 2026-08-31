<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$pageTitle = 'Privacy';
require __DIR__ . '/../views/header.php';
?>
<section class="legal-shell"><p class="landing-kicker">Privacy</p><h1>Your repository remains your repository.</h1><p>WTFCode stores your account, public repository URL, project metadata, derived dependency information, detected symbol names and signatures, bounded relationship evidence excerpts, learning progress, and scan findings. It does not retain complete source files in the application database.</p><p>In hosted production, each repository is cloned into temporary private storage and removed after the request. A later source or Git view may create another temporary clone, which is also removed. The default explanation provider is deterministic and local. If an operator explicitly enables an external provider, the bounded evidence packet and question are sent to that configured service.</p><p>Before a public launch, the operator must publish a retention policy, account deletion and export process, legal contact, and jurisdiction-specific privacy terms.</p></section>
<?php require __DIR__ . '/../views/footer.php'; ?>
