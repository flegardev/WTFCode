<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
$error = null; $saved = false;
$categories = ['confusing' => 'Confusing explanation', 'incorrect' => 'Incorrect finding', 'scan_failure' => 'Failed scan', 'missing_feature' => 'Missing feature', 'usability' => 'Hard to use', 'general' => 'General feedback'];
$areas = ['overview', 'architecture', 'questions', 'change', 'account', 'other'];
if (is_post()) {
    verify_csrf();
    $category = post_string('category'); $area = post_string('area'); $rating = (int) post_string('usefulness');
    $key = LoginRateLimiter::key('feedback:' . Auth::id());
    if (!isset($categories[$category]) || !in_array($area, $areas, true) || $rating < 1 || $rating > 5 || LoginRateLimiter::blocked($key)) $error = 'Choose the category, area and rating. After several reports, please wait 15 minutes.';
    else { LoginRateLimiter::failed($key); Database::connection()->prepare('INSERT INTO pilot_feedback(user_id, category, area, usefulness) VALUES (?, ?, ?, ?)')->execute([Auth::id(), $category, $area, $rating]); $saved = true; }
}
$pageTitle = 'Optional pilot feedback'; $activePage = 'settings';
require __DIR__ . '/../views/header.php';
?>
<section class="app-shell narrow"><section class="auth-card"><h1>Optional pilot feedback</h1><p>Share a category and usefulness rating. We store these with your account for up to 30 days, include them in your export and remove them when you delete your account. No source code, credentials or written message is collected here. Use the agreed pilot contact for a detailed support conversation.</p><?php if ($saved): ?><p role="status">Feedback saved. Thank you for helping us check usefulness.</p><?php endif; ?><?php if ($error): ?><p role="alert"><?= e($error) ?></p><?php endif; ?><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Category<select name="category"><?php foreach ($categories as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><label>Area<select name="area"><?php foreach ($areas as $area): ?><option><?= e($area) ?></option><?php endforeach; ?></select></label><label>How useful was this area? (1 = not useful, 5 = very useful)<select name="usefulness"><?php foreach ([1,2,3,4,5] as $rating): ?><option><?= $rating ?></option><?php endforeach; ?></select></label><button class="button button-primary">Send optional feedback</button></form></section></section>
<?php require __DIR__ . '/../views/footer.php'; ?>
