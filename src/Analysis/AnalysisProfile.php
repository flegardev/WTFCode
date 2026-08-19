<?php

declare(strict_types=1);

final class AnalysisProfile
{
    public const QUICK = 'quick';
    public const DEEP = 'deep';
    public const SECURITY = 'security';
    public const MAXIMUM = 'maximum';

    public static function normalize(string $profile): string
    {
        $profile = strtolower(trim($profile));
        return in_array($profile, [self::QUICK, self::DEEP, self::SECURITY, self::MAXIMUM], true)
            ? $profile
            : self::QUICK;
    }
}
