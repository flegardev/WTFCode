# Alpha batch 4

Repositories tested: django-react, nestjs-starter, svelte-realworld, nuxt-starter, flask-framework.

## Failure accounting

- Failures recorded: 7 (P0 0 / P1 4 / P2 3 / P3 0).
- Fixes made: ALPHA-001, ALPHA-006, ALPHA-008, ALPHA-009, ALPHA-010, ALPHA-011, ALPHA-017.
- Regressions added: tests/V3FeatureEvidenceTest.php — catalogue JSON named chat cannot create AI chat; tests/UnitTest.php — generic decorators do not prove FastAPI and explicit Flask evidence does; tests/AlphaRunnerTest.php — every feature summary carries bounded evidence metadata; tests/UnitTest.php plus provider-version cache invalidation coverage; tests/V3FeatureEvidenceTest.php plus original Flask corpus rerun; tests/AlphaRunnerTest.php — run schema version is part of the resumable contract; tests/AlphaRunnerTest.php — schema 3 invalidates summaries that omit route evidence.

## Product observations

- Best insight: No repository in this batch received manual human review.
- Worst false positive: ALPHA-001: A template catalogue entry named chat was presented as a strong AI chat runtime feature.
- Performance outlier: django-react (2.16s).
- Unresolved limitation: django-react: Partial analyzer providers: tree-sitter. flask-framework: Partial analyzer providers: tree-sitter. flask-framework: Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited.

Machine output is evidence for review, not a human usefulness score.
