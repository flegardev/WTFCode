<?php

declare(strict_types=1);

namespace WTFCode\Application;

final class PilotAccess
{
    public static function mode(): string
    {
        $mode = strtolower((string) (getenv('WTF_PILOT_ACCESS') ?: (app_config()['environment'] === 'production' ? 'closed' : 'open')));
        return in_array($mode, ['open', 'allowlist', 'closed'], true) ? $mode : 'closed';
    }

    public static function emails(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn(string $email): string => strtolower(trim($email)), explode(',', (string) getenv('WTF_PILOT_EMAILS'))), static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    }

    public static function admits(string $email): bool
    {
        return self::mode() === 'open' || (self::mode() === 'allowlist' && count(self::emails()) <= 5 && in_array(strtolower(trim($email)), self::emails(), true));
    }

    public static function requiresVerification(): bool
    {
        $value = getenv('WTF_REQUIRE_EMAIL_VERIFICATION');
        return $value === false ? app_config()['environment'] === 'production' : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
