# Alpha batch 5

Repositories tested: firebase-quickstarts, prisma-examples, drizzle-orm, vercel-ai-chatbot, langchain-nextjs.

## Failure accounting

- Failures recorded: 9 (P0 1 / P1 1 / P2 7 / P3 0).
- Fixes made: ALPHA-003, ALPHA-008, ALPHA-011, ALPHA-017, ALPHA-019, ALPHA-026, ALPHA-027, ALPHA-028.
- Regressions added: tests/V3AstTest.php — semantic output is capped before PHP graph decoding; tests/AlphaRunnerTest.php — every feature summary carries bounded evidence metadata; tests/AlphaRunnerTest.php — run schema version is part of the resumable contract; tests/AlphaRunnerTest.php — schema 3 invalidates summaries that omit route evidence; tests/V3ExplanationTest.php — duplicate route filenames resolve the explicitly named repository path; tests/UnitTest.php — first-screen findings collapse duplicate signatures; tests/V3FalsePositiveTest.php — Select imports cannot create SQL tables; Playwright 390x844 trace acceptance — document scroll width equals viewport width.

## Product observations

- Best insight: vercel-ai-chatbot: The “chat app” is also a document/history/voting/upload system with distinct API and persistence boundaries.
- Worst false positive: ALPHA-003: Quick analysis exhausted 128 MiB while decoding unbounded TypeScript worker evidence.
- Performance outlier: drizzle-orm (20.03s).
- Unresolved limitation: firebase-quickstarts: Partial analyzer providers: tree-sitter. firebase-quickstarts: Failed analyzer providers: typescript-semantic. prisma-examples: Partial analyzer providers: tree-sitter.

Machine output is evidence for review, not a human usefulness score.
