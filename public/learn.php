<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$project = $projectId === false || $projectId === null ? null : Project::findForUser((int) $projectId, Auth::id());
if ($project === null) { http_response_code(404); exit('Project not found.'); }
if (is_post()) {
    verify_csrf();
    $lesson = post_string('lesson');
    $completedLesson = ExplorationService::complete(Auth::id(), (int) $project['id'], $lesson);
    flash($completedLesson ? 'success' : 'error', $completedLesson ? 'Lesson marked complete.' : 'That lesson is not available for this project.');
    redirect('learn.php?id=' . (int) $project['id']);
}
$lessons = ExplorationService::lessons((int) $project['id']);
$progress = ExplorationService::progress(Auth::id(), (int) $project['id']);
$completed = array_flip($progress['completed']);
$pageTitle = 'Learn ' . $project['name'];
$activePage = 'dashboard';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow"><div class="breadcrumb"><a href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>"><?= e($project['name']) ?></a><span>/</span><span>Learn</span></div><div class="project-heading compact"><div><p class="landing-kicker">Learn my app</p><h1><?= $progress['percentage'] ?>% explored</h1><p>These are small lessons derived from the systems WTFCode actually found. Completion is your own progress tracker, not a claim that you have mastered the code.</p></div><a class="button button-quiet" href="<?= e(url('project.php?id=' . (int) $project['id'])) ?>">Back to map</a></div><div class="progress-bar" aria-label="<?= $progress['percentage'] ?> percent explored"><span style="width: <?= $progress['percentage'] ?>%"></span></div><section class="lesson-list"><?php foreach ($lessons as $lesson): ?><article class="lesson <?= isset($completed[$lesson['key']]) ? 'complete' : '' ?>"><div><span><?= isset($completed[$lesson['key']]) ? 'Complete' : 'Next lesson' ?></span><h2><?= e($lesson['title']) ?></h2><p><?= e($lesson['description']) ?></p></div><?php if (isset($completed[$lesson['key']])): ?><b>✓</b><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="lesson" value="<?= e($lesson['key']) ?>"><button class="button button-secondary" type="submit">Mark explored</button></form><?php endif; ?></article><?php endforeach; ?></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>
