<?php

declare(strict_types=1);

interface FrameworkAdapterInterface
{
    /** @param array<int, array<string, mixed>> $files */
    public function enrich(array $files, SymbolGraph $graph): void;
}
