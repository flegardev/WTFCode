<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
header('Cache-Control: no-store, max-age=0');

$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
if (!GitHubConnectState::consume($state, (int) Auth::id())) {
    flash('error', 'GitHub connection expired or could not be verified. Start the connection again.');
    redirect('project-create.php');
}

$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
$installationId = filter_var($_GET['installation_id'] ?? null, FILTER_VALIDATE_INT);
try {
    $installation = GitHubAppService::completeInstallation($code, $installationId === false || $installationId === null ? 0 : (int) $installationId);
    $saved = GitHubInstallationRepository::saveForUser((int) Auth::id(), $installation);
    flash('success', 'GitHub connected for ' . (string) $saved['account_login'] . '. Choose a repository below.');
} catch (Throwable $exception) {
    Logger::warning('GitHub App callback failed', ['user_id' => Auth::id(), 'type' => get_class($exception), 'message' => $exception->getMessage()]);
    $message = $exception instanceof GitHubAccessException ? $exception->safeMessage() : 'GitHub could not be connected. Please try again.';
    flash('error', $message);
}
redirect('project-create.php');
