<?php

declare(strict_types=1);

final class PackageInventoryStore
{
    /** @param array<int, array<string, mixed>> $packages */
    public static function persist(int $projectId, int $scanRunId, array $packages): void
    {
        if ($packages === []) return;
        $statement = Database::connection()->prepare(
            'INSERT INTO package_inventory (project_id, scan_run_id, package_name, package_version, ecosystem, purl, classification, licenses_json, locations_json, providers_json) VALUES (:project_id, :scan_run_id, :package_name, :package_version, :ecosystem, :purl, :classification, :licenses_json, :locations_json, :providers_json)'
        );
        foreach (array_slice($packages, 0, 10000) as $package) {
            if (!is_array($package) || trim((string) ($package['name'] ?? '')) === '') continue;
            $classification = in_array($package['classification'] ?? '', ['declared', 'resolved', 'detected'], true) ? $package['classification'] : 'detected';
            $statement->execute([
                'project_id' => $projectId,
                'scan_run_id' => $scanRunId,
                'package_name' => substr((string) $package['name'], 0, 255),
                'package_version' => substr((string) ($package['version'] ?? ''), 0, 160),
                'ecosystem' => substr((string) ($package['ecosystem'] ?? 'unknown'), 0, 80),
                'purl' => ($package['purl'] ?? '') === '' ? null : substr((string) $package['purl'], 0, 700),
                'classification' => $classification,
                'licenses_json' => self::json($package['licenses'] ?? []),
                'locations_json' => self::json($package['locations'] ?? []),
                'providers_json' => self::json($package['providers'] ?? [$package['provider'] ?? 'unknown']),
            ]);
        }
    }

    private static function json(mixed $value): ?string
    {
        return !is_array($value) || $value === [] ? null : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
