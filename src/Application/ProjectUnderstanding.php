<?php

declare(strict_types=1);

namespace WTFCode\Application;

/** Plain descriptions of stored facts. Never derives a business purpose from a name. */
final class ProjectUnderstanding
{
    public static function summary(array $nodes, array $routes, array $stack): string
    {
        $labels = array_values(array_unique(array_filter(array_map(static fn(array $node): string => (string) ($node['label'] ?? ''), $nodes))));
        if ($routes !== []) {
            $description = 'This project appears to provide a web API: code that receives requests and returns responses.';
        } elseif ($labels !== []) {
            $description = 'This project contains code for ' . implode(', ', array_slice($labels, 0, 3)) . '.';
        } else {
            $description = 'There is not enough supported source evidence to explain this application’s purpose yet.';
        }
        if ($stack !== []) {
            $description .= ' The detected technologies include ' . implode(', ', array_slice($stack, 0, 3)) . '.';
        }
        return $description;
    }
}
