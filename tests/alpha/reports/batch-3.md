# Alpha batch 3

Repositories tested: turborepo, chatbot-ui, vitesse, realworld-index, laravel-vue-ai-native.

## Failure accounting

- Failures recorded: 4 (P0 0 / P1 1 / P2 3 / P3 0).
- Fixes made: ALPHA-008, ALPHA-011, ALPHA-012, ALPHA-017.
- Regressions added: tests/AlphaRunnerTest.php — every feature summary carries bounded evidence metadata; tests/AlphaRunnerTest.php — run schema version is part of the resumable contract; tests/V3FeatureEvidenceTest.php plus original RealWorld index rerun; tests/AlphaRunnerTest.php — schema 3 invalidates summaries that omit route evidence.

## Product observations

- Best insight: realworld-index: This repository describes implementations; it is not itself one of those runtime applications.
- Worst false positive: ALPHA-008: Alpha run summaries retained feature labels but omitted the evidence needed for manual truth review.
- Performance outlier: turborepo (9.36s).
- Unresolved limitation: turborepo: The scanner stopped after the 3,000-file MVP limit. The results still describe scanned files, but omitted files may affect the application. turborepo: Partial analyzer providers: tree-sitter. turborepo: Failed analyzer providers: typescript-semantic.

Machine output is evidence for review, not a human usefulness score.
