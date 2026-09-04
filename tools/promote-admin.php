<?php

declare(strict_types=1);

define('WTF_CODE_NO_SESSION', true);
require_once __DIR__ . '/../bootstrap.php';

$email = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--email=')) {
        $email = strtolower(trim(substr($argument, 8)));
    }
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/promote-admin.php --email=owner@example.com\n");
    exit(2);
}

$statement = Database::connection()->prepare('UPDATE users SET is_admin = :is_admin WHERE email = :email');
$statement->bindValue(':is_admin', true, PDO::PARAM_BOOL);
$statement->bindValue(':email', $email, PDO::PARAM_STR);
$statement->execute();
if ($statement->rowCount() !== 1) {
    fwrite(STDERR, "No account uses that email address. Register it first, then run this command again.\n");
    exit(1);
}

fwrite(STDOUT, "Administrator access granted to {$email}.\n");
