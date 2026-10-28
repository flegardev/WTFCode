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
                <h2 id="ask-dialog-title">What would you like to understand?</h2>
            </div>
            <button class="ask-close" type="button" data-ask-close aria-label="Close Ask WTFCode">Close</button>
        </header>
        <details class="ask-question-disclosure" data-ask-question-disclosure open><summary>Edit question</summary><form class="ask-drawer-form" method="post" action="<?= e(url('ask.php?id=' . $askProjectId)) ?>" data-ask-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label for="ask-drawer-question">What do you need to understand?</label>
            <textarea id="ask-drawer-question" name="question" rows="2" maxlength="500" placeholder="For example: Where does login happen?" required data-ask-question></textarea>
            <div class="ask-suggestions" aria-label="Example questions">
                <?php foreach (['Where does authentication happen?', 'Where is data stored?', 'Is Docker used here?'] as $suggestion): ?>
                    <button type="button" data-ask-suggestion="<?= e($suggestion) ?>"><?= e($suggestion) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="ask-form-actions">
                <p>Answered locally from your last scan. No external AI calls.</p>
                <button class="button button-primary" type="submit" data-ask-submit>Ask codebase</button>
            </div>
            <p class="ask-status" role="status" aria-live="polite" data-ask-status></p>
            <button class="button button-quiet" type="button" data-ask-retry hidden>Try again</button>
        </form></details><p class="ask-current-question" data-ask-current-question hidden></p>
        <div class="ask-loading" data-ask-loading hidden aria-hidden="true">
            <span></span><span></span><span></span>
        </div>
        <section class="ask-drawer-result" data-ask-result hidden tabindex="-1" aria-live="polite">
            <div class="ask-answer-meta" data-ask-meta></div>
            <div class="section-row"><h3>What we found</h3><button class="button button-quiet" type="button" data-copy-answer>Copy answer</button></div>
            <p class="ask-copy-status" data-ask-copy-status role="status"></p><p class="ask-answer-text" data-ask-answer></p>
            <p class="uncertainty-note" data-ask-inference hidden></p>
            <section data-ask-evidence-section hidden>
                <h3>Evidence</h3>
                <ol class="ask-evidence-list" data-ask-evidence></ol>
            </section>
            <p class="uncertainty-note">A source match is a starting point for review. Static analysis cannot confirm runtime behavior or prove that a feature works.</p><details data-ask-symbols-section hidden>
                <summary>Technical details · matched symbols</summary>
                <div class="answer-evidence" data-ask-symbols></div>
            </details>
        </section>
    </div>
</dialog>
