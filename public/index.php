<?php

/**
 * WTFCode Public Landing Page.
 * 
 * BEGINNER NOTE:
 * This is the public homepage entry point for web visitors.
 * If the user is already logged in, it automatically redirects them to `dashboard.php`.
 * Otherwise, it renders the marketing hero section, product feature explanations,
 * security commitments, and calls-to-action to import codebases.
 */

declare(strict_types=1);

// Anonymous marketing requests need no database-backed session. Existing
// session cookies retain the normal authenticated redirect and fail closed.
if (!isset($_COOKIE['wtfcode_session'])) {
    define('WTF_CODE_NO_SESSION', true);
}

// Boot application services and, when required, sessions
require_once __DIR__ . '/../bootstrap.php';

// Redirect logged-in users directly to their dashboard
if (Auth::check()) {
    redirect('dashboard.php');
}

$pageTitle = 'Understand what your AI built';
require __DIR__ . '/../views/header.php';
?>
<section class="landing-hero">
    <div class="landing-copy"><p class="landing-kicker">For the builders figuring it out</p><h1>Understand what<br>your <span>AI built.</span></h1><p>Turn your AI-generated code into clear explanations, visual architecture, and actionable insights. Know what your application does before making your next change.</p><div class="hero-actions"><a class="button button-primary" href="<?= e(url('register.php')) ?>">Analyze your project <span aria-hidden="true">→</span></a><a class="button button-secondary" href="#product-preview">See how it works</a></div></div>
    <div class="hero-preview" id="product-preview"><?php require __DIR__ . '/../views/public-example.php'; ?></div>
</section>
<div class="trust-strip"><span>Read-only static analysis</span><span>No repository code is executed</span><span>Source references stay visible</span></div>
<section class="landing-section story-section" id="how-it-works"><div class="section-intro"><h2>From “it works” to “I get it.”</h2><p>Three steps to understand the app you are building.</p></div><div class="story-grid"><article><strong>01 / Connect</strong><h3>Bring your repository.</h3><p>Paste a public GitHub URL. Your scan runs in the background while you keep working.</p></article><article><strong>02 / Understand</strong><h3>See the bigger picture.</h3><p>Explore the technologies, routes, data layers, and dependencies detected in your source.</p></article><article><strong>03 / Change</strong><h3>Know what to check.</h3><p>Ask questions with source references. Follow dependencies before making your next change.</p></article></div></section>
<section class="landing-section feature-split"><div><h2>Less guessing.<br>More understanding.</h2><p>You do not need to become a developer overnight. Start with the parts that matter to your next decision.</p></div><div class="feature-list"><div><span>What does this app do?</span><p>A plain-language overview with the files behind each finding.</p></div><div><span>What depends on this file?</span><p>Direct and indirect static relationships, with possible consequences labeled as inference.</p></div><div><span>Where does login happen?</span><p>Ask your codebase and inspect the matching source evidence. No external AI service required.</p></div></div></section>
<section class="landing-section security-section" id="security"><p class="landing-kicker">Privacy by design</p><h2>Analysis, not surveillance.</h2><div class="security-points"><p>Public GitHub URLs are restricted to github.com.</p><p>Hosted clones are temporary and removed after each scan.</p><p>Complete source files are not retained; bounded signatures and evidence excerpts can be stored.</p><p>Questions use a local deterministic engine. No customer evidence is sent to external AI providers.</p></div></section>
<section class="landing-cta"><div><h2>Your code. A clearer picture.</h2><p>Start with one public repository. No payment details.</p></div><a class="button button-primary" href="<?= e(url('register.php')) ?>">Analyze your project <span aria-hidden="true">→</span></a></section>
<script src="<?= e(url('assets/js/public-example.js')) ?>" defer></script>
<?php require __DIR__ . '/../views/footer.php'; ?>
