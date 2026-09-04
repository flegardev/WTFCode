<?php

declare(strict_types=1);

namespace WTFCode\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EvidenceFusionCanonicalizationTest extends TestCase
{
    public function testProviderSymbolsReceivePersistenceSafeDefaults(): void
    {
        $result = new \AnalyzerResult(
            'fixture-provider',
            '1.0.0',
            \AnalyzerResult::SUCCESS,
            [
                'symbols' => [[
                    'key' => 'module-key',
                    'path' => 'src\\index.ts',
                    'language' => 'TypeScript',
                    'type' => 'module',
                    'name' => 'index.ts',
                    'qualified_name' => 'src/index.ts',
                    'start_line' => 1,
                ]],
                'relationships' => [],
                'routes' => [],
            ],
        );

        $graph = (new \EvidenceFusion())->fuse([$result]);
        $symbol = $graph['symbols'][0];

        self::assertSame('src/index.ts', $symbol['path']);
        self::assertNull($symbol['signature']);
        self::assertSame('unknown', $symbol['visibility']);
        self::assertFalse($symbol['exported']);
        self::assertNull($symbol['parent_key']);
        self::assertSame(1, $symbol['end_line']);
        self::assertSame('medium', $symbol['confidence']);
    }
}
