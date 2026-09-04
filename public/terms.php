<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$pageTitle = 'Terms';
require __DIR__ . '/../views/header.php';
?>
<section class="legal-shell"><p class="landing-kicker">Terms</p><h1>Use the analysis as a guide, not a guarantee.</h1><p>WTFCode provides automated repository analysis from static file and Git evidence. It can miss dynamic behavior, generated code, indirect dependencies, external infrastructure, or deployment-specific configuration. Always review changes, run your project’s tests, and use version control before modifying a codebase.</p><p>Only import repositories you are authorized to access and analyse.</p></section>
<?php require __DIR__ . '/../views/footer.php';
