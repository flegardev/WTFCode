<?php

declare(strict_types=1);

if (!isset($_COOKIE['wtfcode_session'])) define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';
$pageTitle = 'Pricing';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell pricing-page">
    <div class="pricing-intro"><p class="landing-kicker">Built for independent builders</p><h1>Start with understanding.</h1><p>Try a public repository today. Paid plans are proposals while we validate the product.</p></div>
    <div class="pricing-grid">
        <article class="price-card"><span>Free trial</span><h2>€0</h2><p>Get to know your codebase.</p><ul><li>One public repository</li><li>Five scans per rolling 30 days</li><li>Source-backed questions</li><li>Architecture and change impact</li><li>No payment details</li></ul><a class="button button-secondary" href="<?= e(url(Auth::check() ? 'project-create.php' : 'register.php')) ?>">Get started</a></article>
        <article class="price-card proposed"><div class="section-row"><span>Founder</span><b class="plan-badge">Proposed</b></div><h2>€19 <small>/ month</small></h2><p>For builders maintaining a few projects.</p><ul><li>Proposed: three projects</li><li>Proposed: twenty scans per 30 days</li><li>Bounded repository analysis</li><li>Source-backed explanations</li><li>Private access pending verification</li></ul><p class="price-unavailable">Not available for purchase</p></article>
        <article class="price-card"><div class="section-row"><span>One-time analysis</span><b class="plan-badge">Proposed</b></div><h2>€9 <small>/ report</small></h2><p>A potential option for one project.</p><ul><li>Proposed: one public repository</li><li>One bounded scan</li><li>No recurring subscription</li><li>Report format still to be validated</li></ul><p class="price-unavailable">Not available for purchase</p></article>
    </div>
    <p class="pricing-footnote">All scans are static and may miss dynamic behavior. Current limits: 100 MB checked-out repository, 3,000 readable files, 20 MB total source. External AI calls are disabled. Prices and plan limits may change before launch.</p>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>
