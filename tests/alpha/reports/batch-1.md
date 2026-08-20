# Alpha batch 1

Repositories tested: wtfcode, laravel-starter, adminer, next-postgres-auth, react-crud.

## Failure accounting

- Failures recorded: 11 (P0 0 / P1 3 / P2 8 / P3 0).
- Fixes made: ALPHA-004, ALPHA-007, ALPHA-008, ALPHA-011, ALPHA-013, ALPHA-017, ALPHA-021, ALPHA-029, ALPHA-030, ALPHA-031.
- Regressions added: tests/AlphaRunnerTest.php plus WTFCode exact-commit corpus rerun; tests/V3FeatureEvidenceTest.php — migration plans and service-worker registration stay outside product features; tests/AlphaRunnerTest.php — every feature summary carries bounded evidence metadata; tests/AlphaRunnerTest.php — run schema version is part of the resumable contract; tests/V3FalsePositiveTest.php — manifest and lockfile package presence cannot prove AWS runtime use; tests/AlphaRunnerTest.php — schema 3 invalidates summaries that omit route evidence; tests/V3FalsePositiveTest.php — direct next-auth dependency identifies Auth.js while a generic next key still does not prove Next.js; tests/V3ChangeTest.php — generic application code remains aligned with specific change intent; tests/V3ChangeTest.php — plural dependency intent maps to configuration and dependencies; tests/AlphaRunnerTest.php — route samples omit internal handler_key values.

## Product observations

- Best insight: next-postgres-auth: A small dashboard template exposes a GET seed route beside Auth.js and durable product/user data.
- Worst false positive: ALPHA-004: Self-dogfood attempted an authentication-gated remote despite an explicitly authorized local repository being available.
- Performance outlier: adminer (6.98s).
- Unresolved limitation: wtfcode: Partial analyzer providers: tree-sitter. wtfcode: Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited. laravel-starter: Partial analyzer providers: tree-sitter.

Machine output is evidence for review, not a human usefulness score.
