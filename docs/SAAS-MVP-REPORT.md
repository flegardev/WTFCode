# WTFCode SaaS MVP implementation report

28 October 2026 · baseline `740f5ef` · branch `codex/saas-mvp`

## Verdict

Implemented and verified a substantial public-repository MVP upgrade in the existing PHP application. Native local staging works through registration, import, durable queue processing, architecture, source inspection, questions and change impact. **This is not a certification that the product is ready to accept payments or confidential repositories.** Production has not been deployed or modified.

## What changed

- Positioned the product around **“Understand what your AI built.”** Reworked the landing page, useful project list, public-first onboarding and proposed pricing, using the supplied dark/violet reference. Added a persistent light/dark preference, mobile layouts, useful empty states and inline errors.
- Added a concise static project summary. Preserved the existing architecture graph, source explorer, source-cited Q&A and change workbench. The change view now has readable results, source-file links and visible separation between detected relationships and inferred consequences.
- Added transaction-serialized trial entitlements: one project and five queued scans per rolling 30 days. Duplicate active jobs coalesce without consuming another scan. Failed queued scans count toward usage; retries of the same job do not. Owner and suspension checks run before queue reuse. Existing over-limit projects/evidence are not deleted.
- Defined bounded proposed Founder and one-time profiles separately from payment handling. Every account remains a trial account. There is no customer-accessible plan selector, checkout or billing integration.
- Added an explicit, default-closed private-repository release gate independent of GitHub credentials. Preserved the existing GitHub App implementation and mock authorization tests; a real private App has not been certified.
- Kept customer answers on the local deterministic engine. Environment selection alone cannot incur external AI spend. Preserved external adapters for controlled operator testing, with an output-token bound and an explicit untrusted-evidence system instruction. AI budgets and user consent remain necessary before exposing them.
- Added registration throttling, bounded bcrypt inputs and CSRF rotation on login. Deleted/suspended users lose cached session identity. Public privacy, terms and security pages remain accessible anonymously during database outages.
- Strengthened health checks to inspect queue/session/project contracts. Added isolated Compose staging, an explicit unprivileged container UID, bounded temporary storage, launch/backup guidance and CI configuration validation.

## Important bugs fixed

1. **Stale rescans:** long-running workers reused stored/in-process checkouts and could analyze the same old commit. Every queued scan now obtains a fresh checkout.
2. **Real TypeScript crash:** `getExtends()` returns one node for a class and an array for an interface; spreading the class result crashed analysis. Both forms now work.
3. **Order-dependent call resolution:** declarations are registered for all files before calls are resolved, so alphabetically later imported helpers can be found.
4. **False certainty:** an unresolved external method was linked to any uniquely named project function. Removed this fallback; unbound calls retain uncertainty. Updated the provider version to invalidate old cached results.
5. **Premature success-looking states:** an unscanned project showed zero review leads and empty analysis conclusions. It now shows an explicit queued/failed state and reveals results when evidence is ready.
6. **Overconfident file role:** a helper in a routes directory was described as a reachable page. Route-folder summaries and file explanations now disclose the naming heuristic.
7. **Unbounded submissions:** imports/rescans had no per-customer project/scan cap. Quota checks now share the transaction and per-user lock with enqueueing.
8. **Hidden private rollout:** credentials alone previously exposed private import. An additional operator gate is now required.

## Architecture and operational choices

Kept server-rendered PHP, vanilla JS, PostgreSQL, the isolated Node analyzers and Cytoscape. Added one small entitlement service rather than billing infrastructure or another service. No database migration or new paid dependency was necessary. The database continues to own queue state; workers remain separate long-running processes. Source imports never execute repository code or install repository dependencies.

Read [the audit and staged plan](SAAS-MVP-AUDIT.md) and [the reproducible staging/launch guide](STAGING-AND-LAUNCH.md). The old modernization report is marked historical because its scores, SQLite claims and readiness assertions do not constitute current evidence.

## Actual validation

Local environment: PHP **8.5.12**, Node **24.22.1**, PostgreSQL **17**, Composer lockfile and npm lockfile dependencies. Docker image uses PHP 8.4/Node 24.19 and has **not** been built here. CI targets PHP 8.3 and Node 22; its remote result must be checked separately.

| Check | Result |
|---|---|
| PHP syntax | Passed for 190 application/test files |
| PHPUnit | **33 tests, 89 assertions passed** |
| Portable regressions | **10 scripts passed** |
| AST/analyzer regressions | **3 scripts passed** |
| PostgreSQL integration | **8 scripts passed**, including queue recovery and entitlements |
| Security integration | **2 scripts passed**, including PostgreSQL ownership/session/RLS and security normalization |
| Public import E2E | **1 script passed**, including invalid/not-found URLs and fresh checkout reuse |
| Hosted-mode public scan | Passed real import → queue → worker → persistence → checkout cleanup on disposable localhost PostgreSQL |
| PHPStan / formatter | Passed, no reported errors or formatting changes |
| JavaScript syntax | All configured checks passed |
| npm / Composer audits | No reported vulnerability advisories at verification time |
| Gitleaks on upgrade commits | No leaks reported; redacted output retained locally |
| Browser acceptance | **41 checks passed**, no browser runtime errors; [machine-readable results](browser-verification.json) |
| Final targeted browser checks | Passed uncertain source-role text, mobile change layout and real Q&A drawer |
| Compose | YAML parsed; actual Docker build/run unavailable locally |

Four security binaries were downloaded and SHA-256 verified using the repository's pinned manifest: Gitleaks 8.30.1, OSV-Scanner 2.5.1, Syft 1.51.0 and Grype 0.117.0. Passing these tests is not proof of complete vulnerability coverage.

The representative public scan used `gothinkster/node-express-realworld-example-app` at `30b68e1e881462b2f4164ea09ab4c4f5699c7b0b`: **57 readable files and all five Quick analyzers successful** after the TypeScript repair. A second small public repository, `octocat/Hello-World`, exercised imports, missing checkouts and production-mode cleanup. Neither repository's code or dependencies were executed.

Browser acceptance exercised real account registration/database sessions, deterministic answers with numbered evidence, invalid-CSRF rejection and cross-account denial for project, graph, files, change, questions and scan status. Seven pages loaded without horizontal page overflow at 1440px and 390px. Light-theme preference survived navigation. Invalid-host validation was also exercised in the real import form.

## Genuine screenshots

[Screenshot gallery](SCREENSHOTS.html) · [Markdown captures](SAAS-MVP-SCREENSHOTS.md)

Fourteen original browser captures cover landing, dashboard (including empty/light states), import, analysis, dependencies, change impact, questions, source inspection, proposed pricing, mobile layouts and a real validation error. The landing preview is itself a screenshot of the working public sample. No mock success screens or invented customer statistics were used.

## Security findings and remaining risks

- Private GitHub OAuth, callbacks, organization policies and revocation need a real dedicated staging App exercise before enabling the release gate. Mock scope/freshness/revocation tests passed; this is a different verification level.
- The runtime PostgreSQL role can bypass RLS. Application owner checks remain essential; this upgrade does not claim a restricted-role database redesign.
- Clone size is rejected after checkout. Native installs need an OS/container disk quota to stop disk exhaustion before that point. Compose provides a 512 MiB temporary filesystem, but its container behavior is still unverified.
- Existing parsers/subprocesses have time/output/input bounds; they are not a hardened VM sandbox. Patch parsers/Git, restrict egress, supervise workers and test real hosting limits.
- Password recovery, email verification, operator identity/support contact and tested export/deletion/retention are still missing launch controls. Registration throttling reduces abuse but does not replace verified accounts or perimeter rate limits.
- Only project/scan entitlements and source/repository analysis bounds are enforced. Actual stored-evidence byte accounting, retention quotas and paid entitlements need durable implementation before monetization. Deterministic Q&A has bounded requests but no durable per-customer question counter.
- Static graphs can miss dynamic dispatch, generated code and external infrastructure. Risk scores, role labels, suggested tests and architecture connections can be inferred. A finding is a review lead, not a security guarantee or proof of failure.
- Mobile geometry and common controls were checked in Chromium. Cross-browser, screen-reader and complete WCAG audits remain manual work. Dashboard light mode was visually inspected; every legacy detail screen still needs a full light-theme contrast pass.

## Deployment status and manual verification

Native staging is running locally at `http://127.0.0.1:8876`. Production settings, databases, data and infrastructure were not changed. No production deployment, payment activation, credential reset or paid upgrade occurred. Live provider/database/worker health was not re-certified in this task.

The Compose stack is prepared for a fresh isolated database, separate worker and loopback-only web listener. It requires Docker build/run verification. Historical migrations have Supabase-specific role/database assumptions; native verification used the canonical fresh schema. Review existing database migrations before applying anything to a real environment.

Monthly planning budget: **$0 for local staging; roughly $40–60/month for an initial hosted pilot**, assuming Supabase Pro from $25/month plus an estimated $15–35 total for web/worker compute. This is an estimate, not a purchased configuration or current compute quote. Domain, tax, email and overages are excluded. See [the assumptions and primary pricing sources](STAGING-AND-LAUNCH.md#costs-assumptions-not-purchased-capacity). Customer AI spend is zero on the deterministic path.

## Before accepting payments / next three priorities

1. **Prove staging operations:** build the actual container, verify database permissions/TLS, monitor queue age and worker liveness, exercise crash recovery and low disk, and restore an encrypted backup into a fresh database. Obtain explicit approval before any production change.
2. **Finish customer trust controls:** email verification/recovery, export/deletion, retention/storage accounting, operator/privacy/support terms, real private-App authorization checks and cross-browser/accessibility review.
3. **Validate the paid offer:** recruit public-repository pilot users, measure usefulness and per-scan resources, then implement a durable entitlement store and separately approved payment integration. €19/month and €9/report remain hypotheses.

## Files and commits

[Complete changed-file manifest](SAAS-MVP-FILES.txt). Changes are split into core safeguards, product UI, evidence certainty corrections and staging/evidence commits. Initial commits: `485dbfe`, `1b4fac7`, `ac2f8d8`; subsequent handoff commits are visible in `git log origin/main..codex/saas-mvp`. The review branch is intended for review, not automatic production promotion.

Local raw verification logs are in ignored `output/`. Original captures are also kept there; the reviewed capture set is committed under `docs/screenshots/`. Local tools, credentials, databases, checkouts, dependencies and secrets are excluded from Git.
