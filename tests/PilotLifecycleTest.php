<?php

declare(strict_types=1);
define('WTF_CODE_NO_SESSION', true);
if (getenv('APP_ENV') === 'testing') putenv('APP_URL=http://127.0.0.1:8879');
$_SERVER['REMOTE_ADDR'] = '127.12.13.' . random_int(1, 250);
require_once __DIR__ . '/../bootstrap.php';
use WTFCode\Application\AccountLifecycle;
use WTFCode\Application\AccountData;
use WTFCode\Application\PilotAccess;
use WTFCode\Application\PilotOperations;
function pilot_assert(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
if (!in_array(app_config()['environment'], ['local', 'testing'], true) || !in_array(app_config()['host'], ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Disposable local database required.');
$pdo = Database::connection();
$ids = []; $suffix = bin2hex(random_bytes(6)); $password = 'Disposable-password-97152';
try {
    putenv('WTF_PILOT_ACCESS=allowlist'); putenv('WTF_PILOT_EMAILS=one-' . $suffix . '@wtfcode.local,two-' . $suffix . '@wtfcode.local'); putenv('WTF_REQUIRE_EMAIL_VERIFICATION=true'); putenv('WTF_MAIL_TRANSPORT=file');
    $cacheRequest = new AnalysisRequest(__DIR__ . '/fixtures/v2/plain-php', [['path' => 'test.php', 'content' => '<?php function pilot_cache_fixture() {}', 'language' => 'PHP']]);
    $provider = new NativeAnalyzerProvider();
    $cache = new ProviderCache();
    pilot_assert($cache->save($cacheRequest, $provider, $provider->analyze($cacheRequest)) === null && $cache->load($cacheRequest, $provider) === null, 'Pilot bypasses unowned provider caches');
    pilot_assert(!PilotAccess::admits('outsider@wtfcode.local'), 'Allowlist rejects outsiders');
    pilot_assert(Auth::register('Outside', 'outsider-' . $suffix . '@wtfcode.local', $password, $password) === [], 'Ineligible registration has generic response');
    $q = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?'); $q->execute(['outsider-' . $suffix . '@wtfcode.local']); pilot_assert((int) $q->fetchColumn() === 0, 'No unapproved account created');
    $email = 'one-' . $suffix . '@wtfcode.local';
    pilot_assert(Auth::register('Pilot lifecycle', $email, $password, $password) === [], 'Allowed registration queues verification');
    $q = $pdo->prepare('SELECT id FROM users WHERE email = ?'); $q->execute([$email]); $owner = (int) $q->fetchColumn(); $ids[] = $owner;
    pilot_assert($owner > 0 && Auth::id() === null, 'Verification registration does not create a login session');
    $other = Database::insert('INSERT INTO users(name,email,password_hash) VALUES (?, ?, ?)', ['Other', 'two-' . $suffix . '@wtfcode.local', password_hash($password, PASSWORD_DEFAULT)]); $ids[] = $other;
    AccountLifecycle::requestLink('unknown-' . $suffix . '@wtfcode.local', 'verify');
    AccountLifecycle::drainRequests(10);
    $mail = [];
    foreach (glob(app_config()['storage_path'] . '/mail/*.json') ?: [] as $file) { $message = json_decode((string) file_get_contents($file), true); if ($message['to'] === $email) $mail[] = $message; }
    pilot_assert(count($mail) === 1, 'Controlled mailbox receives only known account verification');
    parse_str((string) parse_url($mail[0]['link'], PHP_URL_FRAGMENT), $fragment); $token = $fragment['token'];
    $q = $pdo->prepare('SELECT token_hash FROM account_tokens WHERE user_id = ?'); $q->execute([$owner]); pilot_assert($q->fetchColumn() === hash('sha256', $token), 'Database retains token digest only');
    pilot_assert(!AccountLifecycle::consume($token, 'reset', $password), 'Token is purpose-bound');
    $pdo->prepare("UPDATE account_tokens SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE user_id = ?")->execute([$owner]);
    pilot_assert(!AccountLifecycle::consume($token, 'verify'), 'Expired verification rejected');
    $pdo->prepare("UPDATE account_tokens SET expires_at = CURRENT_TIMESTAMP + INTERVAL '1 hour' WHERE user_id = ?")->execute([$owner]);
    AccountLifecycle::requestLink($email, 'verify'); AccountLifecycle::drainRequests();
    pilot_assert(!AccountLifecycle::consume($token, 'verify'), 'Resending verification invalidates the previous link');
    $q = $pdo->prepare('SELECT token_hash FROM account_tokens WHERE user_id = ?'); $q->execute([$owner]); $currentHash = $q->fetchColumn();
    foreach (glob(app_config()['storage_path'] . '/mail/*.json') ?: [] as $file) {
        $message = json_decode((string) file_get_contents($file), true);
        if ($message['to'] !== $email || $message['purpose'] !== 'verify') continue;
        parse_str((string) parse_url($message['link'], PHP_URL_FRAGMENT), $parts);
        if (hash('sha256', $parts['token']) === $currentHash) $token = $parts['token'];
    }
    pilot_assert(AccountLifecycle::consume($token, 'verify') && !AccountLifecycle::consume($token, 'verify'), 'Verification succeeds once only');
    pilot_assert(Auth::register('Duplicate signup', $email, 'Different-password-4321', 'Different-password-4321') === [], 'Existing account registration has generic response');
    $q = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?'); $q->execute([$owner]);
    pilot_assert(password_verify($password, $q->fetchColumn()), 'Duplicate signup never overwrites an existing password');
    $project = Database::insert("INSERT INTO projects(user_id,name,repository_url) VALUES (?, 'Deletion fixture', 'https://github.com/example/deletion')", [$owner]);
    try { AnalysisJobStore::enqueue($project, $owner, 'maximum'); throw new RuntimeException('Pilot expensive profile admitted'); } catch (DomainException) { pilot_assert(true, 'Pilot enforces Quick profile on the server'); }
    $job = AnalysisJobStore::enqueue($project, $owner, 'quick', 'initial'); $claimed = AnalysisJobStore::claimNext('pilot-deletion-fixture');
    pilot_assert((int) $claimed['id'] === $job, 'Disposable running job claimed');
    $dir = app_config()['storage_path'] . '/repos/project-' . $project; if (!is_dir($dir)) mkdir($dir, 0700, true); file_put_contents($dir . '/evidence.txt', 'disposable');
    $export = AccountData::export($owner); $otherExport = AccountData::export($other);
    pilot_assert(count($export['projects']) === 1 && $otherExport['projects'] === [] && !str_contains(json_encode($export), 'password_hash'), 'Metadata export excludes credentials and other owners');
    $_SESSION = ['user' => ['id' => $owner, 'session_version' => 0]];
    $sessions = new DatabaseSessionHandler(1800); $sessions->write('pilot-session-' . $suffix, 'sensitive-payload'); $sessions->close();
    AccountLifecycle::requestLink($email, 'reset'); AccountLifecycle::drainRequests();
    $reset = null;
    foreach (glob(app_config()['storage_path'] . '/mail/*.json') ?: [] as $file) { $message = json_decode((string) file_get_contents($file), true); if ($message['to'] === $email && $message['purpose'] === 'reset') $reset = $message; }
    pilot_assert($reset !== null, 'Recovery delivered to local mailbox');
    parse_str((string) parse_url($reset['link'], PHP_URL_FRAGMENT), $fragment); $resetToken = $fragment['token'];
    $pdo->prepare("UPDATE account_tokens SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE user_id = ?")->execute([$owner]);
    pilot_assert(!AccountLifecycle::consume($resetToken, 'reset', 'Changed-disposable-97152'), 'Expired recovery rejected');
    $pdo->prepare("UPDATE account_tokens SET expires_at = CURRENT_TIMESTAMP + INTERVAL '1 hour' WHERE user_id = ?")->execute([$owner]);
    pilot_assert(AccountLifecycle::consume($resetToken, 'reset', 'Changed-disposable-97152'), 'Password reset accepted');
    pilot_assert(!AccountLifecycle::consume($resetToken, 'reset', $password), 'Reset cannot replay');
    $sessions->write('pilot-stale-session-' . $suffix, 'stale'); $sessions->close();
    $q = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = ?'); $q->execute([$owner]); pilot_assert((int) $q->fetchColumn() === 0 && !Auth::isAdmin() && Auth::id() === null, 'Reset revokes sessions and fences late session writes');
    pilot_assert(!AccountData::delete($owner, $password, 'DELETE') && !AccountData::delete($owner, 'Changed-disposable-97152', 'delete'), 'Deletion requires current password and exact confirmation');
    $legacyCacheKey = hash('sha256', 'legacy-pilot-' . $suffix);
    $pdo->prepare("INSERT INTO provider_cache_entries(cache_key,provider_id,provider_version,analysis_version,result_json,expires_at) VALUES (?, 'fixture', '1', '1', '{}', CURRENT_TIMESTAMP + INTERVAL '1 hour')")->execute([$legacyCacheKey]);
    pilot_assert(AccountData::delete($owner, 'Changed-disposable-97152', 'DELETE'), 'Account deletion succeeds');
    pilot_assert((int) $pdo->query('SELECT COUNT(*) FROM provider_cache_entries')->fetchColumn() === 0, 'Deletion invalidates unowned legacy cache evidence');
    pilot_assert(!is_dir($dir) && AnalysisJobStore::find($job) === null, 'Deletion removes checkout and active job');
    $result = (new \WTFCode\Application\ScanJobRunner())->run($claimed);
    pilot_assert($result['state'] === 'failed' && Project::findForUser($project, $owner) === null, 'Stale worker cannot recreate deleted account data');
    foreach (['projects' => 'user_id', 'account_tokens' => 'user_id', 'sessions' => 'user_id', 'pilot_feedback' => 'user_id'] as $table => $column) { $q = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?"); $q->execute([$owner]); pilot_assert((int) $q->fetchColumn() === 0, 'Cascade clears ' . $table); }
    $outside = sys_get_temp_dir() . '/wtf-pilot-outside-' . $suffix;
    mkdir($outside, 0700); file_put_contents($outside . '/keep.txt', 'outside-checkout');
    $link = app_config()['storage_path'] . '/repos/project-' . random_int(800000000, 900000000);
    try {
        if (@symlink($outside, $link)) {
            RepositoryImporter::cleanup($link);
            pilot_assert(!is_link($link) && file_get_contents($outside . '/keep.txt') === 'outside-checkout', 'Checkout-root symlink cleanup preserves its external target');
        } elseif (PHP_OS_FAMILY === 'Windows') echo "SKIP Checkout-root symlink creation unavailable under this Windows account.\n";
        else throw new RuntimeException('Could not create symlink isolation fixture.');
    } finally { if (is_link($link)) unlink($link); unlink($outside . '/keep.txt'); rmdir($outside); }
    PilotOperations::heartbeat('pilot-test'); $status = PilotOperations::status(); pilot_assert((int) $status['live_workers'] >= 1, 'Worker liveness is observable');
    for ($i = 0; $i < 8; $i++) AccountLifecycle::requestLink('rate-' . $suffix . '@wtfcode.local', 'reset');
    $q = $pdo->prepare('SELECT COUNT(*) FROM account_link_requests WHERE email = ?'); $q->execute(['rate-' . $suffix . '@wtfcode.local']); pilot_assert((int) $q->fetchColumn() <= 5, 'Recovery requests are durably rate limited');
    echo "Pilot lifecycle integration passed.\n";
} finally {
    $_SESSION = [];
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($ids as $id) $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM account_link_requests WHERE email LIKE ?')->execute(['%' . $suffix . '%']);
    $pdo->exec("DELETE FROM worker_heartbeats WHERE worker_id IN ('pilot-test', 'pilot-deletion-fixture')");
}
