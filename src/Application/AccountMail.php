<?php

declare(strict_types=1);

namespace WTFCode\Application;

use RuntimeException;

final class AccountMail
{
    public static function send(string $email, string $purpose, string $token): void
    {
        $transport = (string) (getenv('WTF_MAIL_TRANSPORT') ?: 'disabled');
        $origin = (string) app_config()['app_url'];
        $host = parse_url($origin, PHP_URL_HOST);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new RuntimeException('Invalid account message.');
        }
        if (!in_array($purpose, ['verify', 'reset'], true)) {
            throw new RuntimeException('Invalid account message purpose.');
        }
        // Fragments never enter HTTP access logs. Consumption requires a CSRF POST.
        $link = $origin . '/account-token.php?purpose=' . $purpose . '#token=' . $token;
        if ($transport === 'file') {
            if (!in_array(app_config()['environment'], ['local', 'testing'], true) || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
                throw new RuntimeException('Test mailbox requires isolated localhost.');
            }
            $directory = app_config()['storage_path'] . '/mail';
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Test mailbox unavailable.');
            }
            $path = $directory . '/' . bin2hex(random_bytes(16)) . '.json';
            $handle = fopen($path, 'x');
            if ($handle === false) {
                throw new RuntimeException('Test mailbox unavailable.');
            }
            chmod($path, 0600);
            fwrite($handle, json_encode(['to' => $email, 'purpose' => $purpose, 'link' => $link], JSON_THROW_ON_ERROR));
            fclose($handle);
            return;
        }
        $from = (string) getenv('WTF_MAIL_FROM');
        if ($transport !== 'mail' || getenv('WTF_LIVE_MAIL_APPROVED') !== 'true' || parse_url($origin, PHP_URL_SCHEME) !== 'https' || !filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $from)) {
            throw new RuntimeException('Account mail is not configured and approved.');
        }
        $subject = $purpose === 'verify' ? 'Verify your WTFCode email' : 'Reset your WTFCode password';
        $body = $subject . "\n\nOpen this link, then confirm the action:\n" . $link . "\n\nThe link is single-use and expires. If you did not request this, ignore it. Your password is never emailed.\n";
        if (!mail($email, $subject, $body, ['From' => $from, 'Content-Type' => 'text/plain; charset=UTF-8'])) {
            throw new RuntimeException('Account message delivery failed.');
        }
    }
}
