<?php

declare(strict_types=1);

interface LanguageAdapterInterface
{
    public function supports(array $file): bool;

    public function extract(array $file, SymbolGraph $graph): void;
}
