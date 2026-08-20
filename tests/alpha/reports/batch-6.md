# Alpha batch 6

Repositories tested: microservices-demo, create-t3-turbo.

## Failure accounting

- Failures recorded: 6 (P0 0 / P1 3 / P2 3 / P3 0).
- Fixes made: ALPHA-008, ALPHA-011, ALPHA-014, ALPHA-015, ALPHA-017.
- Regressions added: tests/AlphaRunnerTest.php — every feature summary carries bounded evidence metadata; tests/AlphaRunnerTest.php — run schema version is part of the resumable contract; tests/V3FalsePositiveTest.php — SQL-like README prose cannot create a database table; tests/V3FeatureEvidenceTest.php — release scripts and load generators cannot create product features; tests/AlphaRunnerTest.php — schema 3 invalidates summaries that omit route evidence.

## Product observations

- Best insight: microservices-demo: None beyond confirming deployment-heavy checkout/search code.
- Worst false positive: ALPHA-008: Alpha run summaries retained feature labels but omitted the evidence needed for manual truth review.
- Performance outlier: microservices-demo (2.93s).
- Unresolved limitation: microservices-demo: Partial analyzer providers: tree-sitter. microservices-demo: Failed analyzer providers: typescript-semantic. microservices-demo: Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited.

Machine output is evidence for review, not a human usefulness score.
