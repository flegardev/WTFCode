<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
if (!is_post()) { http_response_code(405); exit('Method not allowed.'); }
verify_csrf();
if (!GitHubAppService::configured()) {
    flash('error', 'Private repository access is not configured yet.');
    redirect('project-create.php');
}
$state = GitHubConnectState::begin((int) Auth::id());
$destination = GitHubAppService::installationUrl($state);
session_write_close();
header('Cache-Control: no-store, max-age=0');
header('Location: ' . $destination, true, 303);
exit;
