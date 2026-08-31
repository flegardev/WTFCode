<?php

declare(strict_types=1);

if (!isset($project) || !is_array($project) || !isset($project['id'])) return;
$askProjectId = (int) $project['id'];
?>
<dialog class="ask-dialog" id="ask-codebase-panel" data-ask-dialog data-endpoint="<?= e(url('ask.php?id=' . $askProjectId)) ?>" data-symbol-base="<?= e(url('symbol.php?project=' . $askProjectId . '&id=')) ?>" aria-labelledby="ask-dialog-title">
    <div class="ask-drawer">
        <header class="ask-drawer-header">
            <div>
                <span>Ask WTFCode</span>
                <h2 id="ask-dialog-title">Question this codebase</h2>
            </div>
            <button class="ask-close" type="button" data-ask-close aria-label="Close Ask WTFCode">Close</button>
        </header>
        <form class="ask-drawer-form" method="post" action="<?= e(url('ask.php?id=' . $askProjectId)) ?>" data-ask-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label for="ask-drawer-question">What do you need to understand?</label>
            <textarea id="ask-drawer-question" name="question" rows="4" maxlength="500" placeholder="Ask about a feature, symbol, route, file, or risky change." required data-ask-question></textarea>
            <div class="ask-suggestions" aria-label="Example questions">
                <?php foreach (['Where does authentication happen?', 'What breaks if I change the user model?', 'How does a request reach the database?', 'Can I safely remove this class?'] as $suggestion): ?>
                    <button type="button" data-ask-suggestion="<?= e($suggestion) ?>"><?= e($suggestion) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="ask-form-actions">
                <p>Uses bounded, redacted evidence. Configured external providers receive only that evidence packet.</p>
                <button class="button button-primary" type="submit" data-ask-submit>Ask codebase</button>
            </div>
            <p class="ask-status" role="status" aria-live="polite" data-ask-status></p>
        </form>
        <div class="ask-loading" data-ask-loading hidden aria-hidden="true">
            <span></span><span></span><span></span>
        </div>
        <section class="ask-drawer-result" data-ask-result hidden tabindex="-1" aria-live="polite">
            <div class="ask-answer-meta" data-ask-meta></div>
            <h3>Answer</h3>
            <p class="ask-answer-text" data-ask-answer></p>
            <p class="uncertainty-note" data-ask-inference hidden></p>
            <section data-ask-evidence-section hidden>
                <h3>Evidence</h3>
                <ol class="ask-evidence-list" data-ask-evidence></ol>
            </section>
            <section data-ask-symbols-section hidden>
                <h3>Matched symbols</h3>
                <div class="answer-evidence" data-ask-symbols></div>
            </section>
        </section>
    </div>
</dialog>
