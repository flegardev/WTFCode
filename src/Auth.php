<?php

declare(strict_types=1);

final class Auth
{
    public static function user(): ?array
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            flash('error', 'Log in to view your repository workspace.');
            redirect('login.php');
        }
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
    }

    /** @return array<string, string> */
    public static function register(string $name, string $email, string $password, string $confirmation): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        $errors = [];

        if ($name === '' || text_length($name) > 100) {
            $errors['name'] = 'Enter a name up to 100 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || text_length($email) > 190) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (strlen($password) < 10) {
            $errors['password'] = 'Use at least 10 characters for your password.';
        }
        if ($password !== $confirmation) {
            $errors['confirmation'] = 'Passwords do not match.';
        }
        if ($errors !== []) {
            return $errors;
        }

        try {
            $statement = Database::connection()->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)');
            $statement->execute(['name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return ['email' => 'An account already uses that email address.'];
            }
            throw $exception;
        }

        self::loginById((int) Database::connection()->lastInsertId());
        return [];
    }

    public static function attempt(string $email, string $password): bool
    {
        $statement = Database::connection()->prepare('SELECT id, name, email, password_hash FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => strtolower(trim($email))]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Logger::warning('Login failed', ['email_hash' => hash('sha256', strtolower(trim($email)))]);
            return false;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::connection()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
        }
        self::storeUser($user);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    private static function loginById(int $id): void
    {
        $statement = Database::connection()->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        if (!$user) {
            throw new RuntimeException('New account could not be loaded.');
        }
        self::storeUser($user);
    }

    private static function storeUser(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']];
    }
}
