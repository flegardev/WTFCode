# SaaS MVP audit and implementation plan

Audit baseline: `740f5ef`, 28 October 2026. Existing checkout was clean and matched `origin/main`.

## Preserve

- PHP 8.3 server-rendered application and vanilla JS; no frontend rewrite needed.
- `FileCollector` bounds source to 3,000 files, 256 KiB/file, 20 MiB total and rejects symlinks.
- Isolated Node AST providers, PHP parser, evidence fusion, source citations, change impact and Cytoscape graphs.
- PostgreSQL jobs with ownership checks, leases, heartbeats, retries and recovery; separate CLI worker.
- Prepared queries, CSRF, database sessions, login throttling, owner-scoped project lookups and GitHub installation ownership checks.
- Public landing works during database outages. Baseline portable tier: 10 scripts passed locally.

## Highest-priority findings

1. No customer project/scan quotas. Unlimited accounts and scan submissions can consume worker/storage resources.
2. Private imports become available when credentials are present; there is no separate release gate for verified private access.
3. Optional external explanation providers have no output-token ceiling or customer budget. Keep customer Q&A deterministic until paid-provider accounting is implemented.
4. Git checkout size is checked only after clone; a large import can exhaust disk before rejection. Existing process timeout is 90 seconds. Hard OS disk quotas remain a hosting requirement.
5. Dashboard emphasizes aggregate exploration metrics, and import begins with private access instead of the simple public path.
6. Public README overstates readiness; an old modernization report includes unsupported scores and SQLite claims contradicted by current configuration. Treat that report as historical, not launch evidence.
7. `/health.php` checks only the users table, not queue/schema readiness. No reproducible composed staging stack exists.
8. Local checkout has production PHP dependencies only; Node/npm are absent from PATH. Install development dependencies before claiming quality-gate results.

## Stages

1. P0: default-closed private release gate; transaction-serialized user quotas for public imports/rescans; deterministic customer explanations; session invalidation for removed/suspended accounts; regression tests.
2. P0/P1: positioning and restrained violet UI based on supplied reference, useful project list, public-first onboarding, theme preference, proposed pricing explicitly unavailable for purchase. Preserve source-backed project/graph/change/file workflows.
3. Verification/operations: disposable local PostgreSQL and real worker; public GitHub scans and browser owner/CSRF/error/mobile checks; genuine screenshots; Docker Compose staging and launch checklist; reviewable commits.

## Verification boundaries

No production deployment, live infrastructure change, paid resource, billing activation or live database migration is authorized. A local staging run can verify application behavior, but cannot certify GitHub OAuth/private repository access or hosted-worker availability. Static findings are review leads, not a security guarantee. Costs and paid plan prices must remain assumptions.
