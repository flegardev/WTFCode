# Alpha batch 2

Repositories tested: express-realworld, fastapi-full-stack, django-framework, supabase-payments, docker-getting-started.

## Failure accounting

- Failures recorded: 13 (P0 0 / P1 7 / P2 6 / P3 0).
- Fixes made: ALPHA-002, ALPHA-005, ALPHA-007, ALPHA-008, ALPHA-011, ALPHA-014, ALPHA-015, ALPHA-016, ALPHA-017, ALPHA-018, ALPHA-020.
- Regressions added: tests/AlphaRunnerTest.php — successful child exit codes survive delayed Windows process polling; tests/V3FalsePositiveTest.php — comments and website strings do not prove services while API_BASE_URL does; tests/V3FeatureEvidenceTest.php — migration plans and service-worker registration stay outside product features; tests/AlphaRunnerTest.php — every feature summary carries bounded evidence metadata; tests/AlphaRunnerTest.php — run schema version is part of the resumable contract; tests/V3FalsePositiveTest.php — SQL-like README prose cannot create a database table; tests/V3FeatureEvidenceTest.php — release scripts and load generators cannot create product features; tests/V3FalsePositiveTest.php — comments, metadata URLs, and generic API version fields cannot prove services; tests/AlphaRunnerTest.php — schema 3 invalidates summaries that omit route evidence; tests/V3ExplanationTest.php — confirmed dependents and no-detected-use answers both refuse unsafe deletion claims; tests/Benchmark.php --id=express-realworld — public benchmark requires Express and Node API.

## Product observations

- Best insight: express-realworld: The social relationships live in Prisma join tables even though the route layer presents them as follows/favorites.
- Worst false positive: ALPHA-002: A completed partial scan was stored as a crash after Windows returned -1 from proc_close.
- Performance outlier: django-framework (13.59s).
- Unresolved limitation: express-realworld: Partial analyzer providers: tree-sitter. express-realworld: Failed analyzer providers: typescript-semantic. fastapi-full-stack: Partial analyzer providers: tree-sitter.

Machine output is evidence for review, not a human usefulness score.
