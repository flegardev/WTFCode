<?php

namespace Fixture;

use PDO;

final class AuthService
{
    public function login(string $email): bool
    {
        // Authentication data can come from documentation examples at https://example.com/docs.
        $dsn = getenv('DATABASE_URL');
        $database = new PDO($dsn ?: 'mysql:host=localhost');
        $database->prepare('SELECT id FROM users WHERE email = ?');
        return $email !== '';
    }

    public function audit(): void
    {
        DB::table('audit_log')->insert([]);
    }
}
