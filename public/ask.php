<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
$question = '';
$result = null;
$error = null;
$errorStatus = 422;
$wantsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
if (is_post()) {
    if ($wantsJson && !csrf_token_is_valid($_POST['csrf_token'] ?? '', $_SESSION['csrf_token'] ?? '')) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        http_response_code(419);
        echo json_encode(['error' => 'This question form expired. Refresh the page and try again.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    verify_csrf();
    $question = post_string('question');
    if (text_length($question) > 500) $question = function_exists('mb_substr') ? mb_substr($question, 0, 500) : substr($question, 0, 500);
    if ($question === '') {
        $error = 'Enter a question about this codebase.';
    } else {
        try {
            $result = ExplanationService::answerQuestion((int) $project['id'], $question);
        } catch (Throwable $exception) {
            Logger::error('Codebase question failed', ['project_id' => (int) $project['id'], 'exception' => get_class($exception)]);
            $error = 'WTFCode could not answer this question right now. Please try again.';
            $errorStatus = 500;
        }
    }
}
if ($wantsJson && is_post()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    if ($error !== null) {
        http_response_code($errorStatus);
        echo json_encode(['error' => $error], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $trace = is_array($result['trace'] ?? null) ? $result['trace'] : [];
    $evidence = array_values(array_filter(is_array($result['evidence_packet']['evidence'] ?? null) ? $result['evidence_packet']['evidence'] : [], 'is_array'));
    $symbols = array_map(static fn (array $symbol): array => [
        'id' => (int) ($symbol['id'] ?? 0),
        'name' => (string) ($symbol['name'] ?? ''),
        'type' => (string) ($symbol['type'] ?? 'symbol'),
        'path' => (string) ($symbol['path'] ?? ''),
    ], array_slice(is_array($trace['symbols'] ?? null) ? $trace['symbols'] : [], 0, 12));
    echo json_encode([
        'answer' => (string) ($result['answer'] ?? ''),
        'provider' => (string) ($result['provider'] ?? 'deterministic'),
        'provider_fallback' => !empty($result['provider_fallback']),
        'inferences' => is_array($result['inferences'] ?? null) ? $result['inferences'] : [],
        'confidence' => (string) ($trace['confidence'] ?? 'low'),
        'evidence' => array_slice($evidence, 0, 40),
        'symbols' => $symbols,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}
$pageTitle = 'Ask ' . $project['name'];
$activePage = 'dashboard';
$activeProjectSection = '';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow ask-page">
    <div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Ask</span></div>
    <div class="project-heading compact"><div><p class="landing-kicker">Ask WTFCode</p><h1>Start with the evidence.</h1><p>Questions use bounded, redacted evidence. Every supported claim from an optional provider must cite its evidence number.</p></div></div>
    <?php require __DIR__ . '/../views/project-nav.php'; ?>
    <section class="ask-card">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label for="question">What do you want to understand?</label>
            <textarea id="question" name="question" rows="4" maxlength="500" placeholder="Where does login happen? What breaks if I change User.php?" required><?= e($question) ?></textarea>
            <?php if ($error !== null): ?><div class="inline-error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <button class="button button-primary" type="submit">Ask this project</button>
        </form>
        <?php if ($result !== null): ?>
            <article class="answer-card">
                <div class="ask-answer-meta"><span><b>Confidence</b><?= e($result['trace']['confidence'] ?? 'low') ?></span><span><b>Provider</b><?= e($result['provider']) ?><?= $result['provider_fallback'] ? ' fallback' : '' ?></span></div>
                <h2>Answer</h2>
                <p class="ask-answer-text"><?= e($result['answer']) ?></p>
                <?php if (($result['inferences'] ?? []) !== []): ?><p class="uncertainty-note">Sentences without a valid graph citation are explicitly labeled as inference.</p><?php endif; ?>
                <?php if (($result['evidence_packet']['evidence'] ?? []) !== []): ?><h3>Numbered evidence</h3><ol class="ask-evidence-list"><?php foreach ($result['evidence_packet']['evidence'] as $evidence): ?><li id="evidence-<?= (int) $evidence['id'] ?>"><strong>[<?= (int) $evidence['id'] ?>] <?= e($evidence['label']) ?></strong><span><?= e($evidence['path']) ?>:<?= (int) $evidence['line'] ?> | <?= e($evidence['confidence']) ?> confidence</span></li><?php endforeach; ?></ol><?php endif; ?>
                <?php if (($result['trace']['symbols'] ?? []) !== []): ?><h3>Matched symbols</h3><div class="answer-evidence"><?php foreach (array_slice($result['trace']['symbols'], 0, 12) as $symbol): ?><a href="<?= e(url('symbol.php?project=' . (int) $project['id'] . '&id=' . (int) $symbol['id'])) ?>"><strong><?= e($symbol['name']) ?></strong><span><?= e(str_replace('_', ' ', $symbol['type'])) ?> in <?= e($symbol['path']) ?></span></a><?php endforeach; ?></div><?php endif; ?>
            </article>
        <?php endif; ?>
    </section>
</section>
<?php require __DIR__ . '/../views/footer.php'; ?>
