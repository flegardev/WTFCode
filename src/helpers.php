<?php

declare(strict_types=1);

function app_config(bool $fresh = false): array
{
    static $config = null;
    if ($config === null || $fresh) {
        $config = require __DIR__ . '/../config/database.php';
        $local = __DIR__ . '/../config/database.local.php';
        if (is_file($local)) {
            $config = array_replace($config, require $local);
        }
    }
    return $config;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return app_config()['app_url'] . '/' . ltrim($path, '/');
}

function request_is_secure(): bool
{
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)) return true;
    if (!app_config()['vercel']) return false;
    $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    return $forwarded === 'https';
}

function client_ip(): string
{
    if (app_config()['vercel']) {
        $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '');
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) return $forwarded;
    }
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) $nonce = base64_encode(random_bytes(18));
    return $nonce;
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-" . csp_nonce() . "'; connect-src 'self'");
}

function release_session_lock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

function resume_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    $known = $_SESSION['csrf_token'] ?? '';
    if (!csrf_token_is_valid($submitted, $known)) {
        http_response_code(419);
        exit('This form expired. Please return to the previous page and try again.');
    }
}

function csrf_token_is_valid(mixed $submitted, mixed $known): bool
{
    return is_string($submitted) && is_string($known) && $submitted !== '' && $known !== '' && hash_equals($known, $submitted);
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $message = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $message;
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function text_length(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}
