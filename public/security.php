<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$pageTitle = 'Security';
require __DIR__ . '/../views/header.php';
?>
<section class="legal-shell"><p class="landing-kicker">Security</p><h1>What this MVP protects.</h1><p>WTFCode restricts imports to public HTTPS repositories at github.com, keeps temporary clones outside the public web directory, uses prepared database statements, CSRF protection, password hashing, database-backed sessions, login throttling, and ownership checks. Git is invoked with structured arguments instead of a shell command, clone recursion excludes submodules, and imported repository code is never installed or executed.</p><h2>Bounded analysis</h2><p>The MVP rejects cloned repositories larger than 100 MB and scans at most 3,000 readable files, 256 KB per file, and 20 MB of readable source. It skips symlinks and common dependency/build directories. A limit reached during a scan is disclosed in the project findings. Hosted deployments should use Quick by default; heavier profiles can exceed platform request limits on larger repositories.</p><h2>What it does not claim</h2><p>This MVP does not claim bank-grade security, compliance certification, automated malware scanning, private GitHub OAuth, or complete vulnerability detection. Review imported repositories as you would any third-party code.</p></section>
<?php require __DIR__ . '/../views/footer.php'; ?>
