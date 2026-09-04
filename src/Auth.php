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

    public static function isAdmin(): bool
    {
        if (!self::refreshUser()) return false;
        $user = self::user();
        return $user !== null && self::flag($user['is_admin'] ?? false) && !self::flag($user['is_suspended'] ?? false);
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            flash('error', 'Administrator access is required for that area.');
            redirect('dashboard.php');
        }
    }

    public static function requireLogin(): void
    {
        if (!self::check() || !self::refreshUser()) {
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
            $userId = Database::insert('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)', ['name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $exception) {
            if (Database::isUniqueViolation($exception)) {
                return ['email' => 'An account already uses that email address.'];
            }
            throw $exception;
        }

        self::loginById($userId);
        return [];
    }

    public static function attempt(string $email, string $password): bool
    {
        $rateKey = LoginRateLimiter::key($email);
        if (LoginRateLimiter::blocked($rateKey)) {
            Logger::warning('Login rate limit reached', ['attempt_hash' => $rateKey]);
            return false;
        }
        $statement = Database::connection()->prepare('SELECT id, name, email, password_hash, is_admin, is_suspended FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => strtolower(trim($email))]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            LoginRateLimiter::failed($rateKey);
            Logger::warning('Login failed', ['email_hash' => hash('sha256', strtolower(trim($email)))]);
            return false;
        }
        if (self::flag($user['is_suspended'] ?? false)) {
            Logger::warning('Suspended login blocked', ['user_id' => (int) $user['id']]);
            return false;
        }
        self::promoteConfiguredAdmin($user);
        LoginRateLimiter::clear($rateKey);
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
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
    }

    private static function loginById(int $id): void
    {
        $statement = Database::connection()->prepare('SELECT id, name, email, is_admin, is_suspended FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        if (!$user) {
            throw new RuntimeException('New account could not be loaded.');
        }
        if (self::flag($user['is_suspended'] ?? false)) {
            throw new RuntimeException('The new account is suspended.');
        }
        self::promoteConfiguredAdmin($user);
        self::storeUser($user);
    }

    private static function storeUser(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'is_admin' => self::flag($user['is_admin'] ?? false),
            'is_suspended' => self::flag($user['is_suspended'] ?? false),
        ];
    }

    private static function refreshUser(): bool
    {
        $user = self::user();
        if ($user === null) return false;
        $statement = Database::connection()->prepare('SELECT id, name, email, is_admin, is_suspended FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => (int) $user['id']]);
        $record = $statement->fetch();
        if (!is_array($record)) return false;
        $_SESSION['user'] = [
            'id' => (int) $record['id'],
            'name' => $record['name'],
            'email' => $record['email'],
            'is_admin' => self::flag($record['is_admin'] ?? false),
            'is_suspended' => self::flag($record['is_suspended'] ?? false),
        ];
        return !self::flag($record['is_suspended'] ?? false);
    }

    /** @param array<string, mixed> $user */
    private static function promoteConfiguredAdmin(array &$user): void
    {
        $configuredEmail = strtolower(trim((string) (app_config()['admin_email'] ?? '')));
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if ($configuredEmail === '' || $email === '' || !hash_equals($configuredEmail, $email)) {
            return;
        }
        Database::connection()->prepare('UPDATE users SET is_admin = :is_admin WHERE id = :id')->execute(['is_admin' => true, 'id' => (int) $user['id']]);
        $user['is_admin'] = true;
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || strtolower((string) $value) === 't' || strtolower((string) $value) === 'true';
    }
}
