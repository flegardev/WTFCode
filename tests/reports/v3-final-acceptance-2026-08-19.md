# WTFCode V3 final acceptance — 2026-08-19

This report records observed acceptance behavior from the V3 checkpoint series ending at `6a4effb` plus the final truthfulness, cache, memory, and product-surface repairs in the final acceptance commit.

## Truthfulness and reliability repairs

- A shared `RuntimeEvidencePolicy` now excludes tests, fixtures, benchmarks, documentation, examples, reports, snapshots, security rules, generated output, dogfood tooling, and analyzer implementation evidence from runtime feature/service claims.
- Signal matching uses token boundaries: `plan` no longer matches `explanation`, for example.
- Detector definitions are recognized semantically from analysis APIs and pattern/signal declarations; the policy does not blacklist Billing or rely on project-specific detector filenames.
- Regression fixtures prove that fake Billing in tests, Stripe in detector source, and README authentication prose do not create runtime features, while a runtime billing route and Stripe client call still create Billing and Payments.
- `.playwright-cli` is excluded from repository discovery.
- Dirty Git worktrees use a content digest instead of a clean commit SHA for provider-cache keys.
- Disagreement diagnostics are built before the full fused graph, preventing the public benchmark from exceeding the 128 MiB PHP ceiling.
- Understand mode filters external services before display, route cards expose stored input/response/effect evidence, and every Learn chapter links to its supporting project view.

## Authenticated browser acceptance

Observed with the disposable localhost project at desktop width and at `390 × 844`:

- Understand rendered architecture, 11 cleaned feature clusters, data boundaries, five unique runtime service identities, and deployment evidence. Billing and Payments were absent.
- Change produced purpose/dependency, risk/test, trace, and safe-prompt output for feature, symbol, file, route, and table targets.
- Review compared `f224283` to `892b0b5`, reported 32 files across six areas, 87 added symbols, 10 schema changes, 40 security-boundary changes, one architecture change, and conservatively labelled potential scope drift. Before/After Change Guard capture completed and rendered tests plus limitations.
- Secure rendered zero confirmed findings, two process-execution review leads, 27 inventoried packages, zero secret findings, zero vulnerable-dependency findings, and independent provider statuses.
- Learn rendered 12 evidence links; marking one chapter changed progress to 8%, and the state survived reload plus logout/relogin.
- The Cytoscape graph rendered all five levels of detail. Search, risk, feature, and confidence controls changed state; reset restored defaults; clicking an architecture node rendered its evidence and neighbor focus; double-click drilled to subsystem; a real connected pair returned `2 nodes · confidence-weighted cost 1`.
- Symbol, table, and environment-variable evidence pages rendered. The environment-variable view showed the key name and source location without a secret value.
- Prompt Builder produced a scoped prompt without either disposable browser password. Ask WTFCode returned numbered deterministic evidence beginning with the `/login.php` route.
- Analyzer health expanded to the exact doctor output. Semgrep and ctags were visibly optional/unavailable without aborting the scan.
- A second browser-created account received HTTP 404 and `Project not found.` for project 36. The account was removed after the check.
- Eleven core pages and the graph reported `scrollWidth = innerWidth = 390`; the mobile graph retained a 358 × 560 canvas viewport and single-column path controls.
- Final browser console result: 0 errors and 0 warnings.

## Automated acceptance

- PHP lint passed for 138 PHP files.
- Unit, V2 analysis/integration, every V3 phase/regression suite, worker syntax checks, and the new runtime feature-evidence test passed.
- Fusion benchmark: 27/27 curated facts, zero enumerated false positives, recall 1.0000, curated precision 1.0000; fusion added 77 confirmed and 27 multi-engine facts without improving this set's native recall.
- Public benchmark: 6/6 repositories passed (Laravel, Adminer, Next.js template, React CRUD, Express RealWorld, FastAPI).
- Redacted Gitleaks working-tree scan inspected about 3.02 MB and reported no leaks; managed binary/PHAR artifacts over the explicit 1 MB target limit were skipped and named by the scanner.
- Maximum dogfood also ran Gitleaks over Git history and returned zero findings.

## Remaining limits

- Tree-sitter remains partial on one self-repository file and compacts evidence to the fusion budget.
- Semgrep and ctags are not installed.
- Product enrichment can hit its explicit derived-relationship budget; this is reported as `product_intelligence_limited=1` rather than hidden.
- The fusion benchmark's precision is limited to four enumerated forbidden facts and is not whole-repository precision.
- Static analysis cannot prove runtime-only dispatch, environment values, authorship, intent violations, or production exploitability.
