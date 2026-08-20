# WTFCode Alpha final report

## Recommendation

WTFCode is ready for a **supervised 5–10-user Alpha on conventional single-application repositories**. It is not ready for broad, unsupervised reliance across arbitrary monorepos or polyglot microservices. Users must see partial-analysis banners and inspect evidence before asking an AI coding agent to make consequential changes.

This recommendation is based on exact pinned public repositories, isolated static scans, eight manual reviews, real browser acceptance, and regression tests. It is not based on a claim that every machine finding was manually verified.

## Corpus

The corpus contains 27 public repositories at exact commit SHAs. It spans Next.js, React, Vue, Nuxt, SvelteKit, Laravel, plain PHP, Express, NestJS, FastAPI, Django, Flask, Supabase, Firebase, Prisma, Drizzle, Docker, monorepos, and a polyglot gRPC/Kubernetes microservices system. It includes tiny starters, mature frameworks, old single-file PHP, full-stack applications, examples registries, AI applications, and large multi-language workspaces.

WTFCode itself is one member, not the center of the evaluation. Repositories described by their authors as AI-native are recorded as such; other rapid-development candidates are not claimed to be AI-generated. Full metadata and selection reasons are in `tests/alpha/corpus.json`.

Safety boundary: the harness cloned public repositories through HTTPS at pinned commits and performed static analysis only. It did not install dependencies, execute repository code, run imported tests, start containers, invoke Makefiles, or execute hooks.

## Reliability

Both final profiles use run schema 3 and analyzer `alpha.10`:

| Profile | Repositories | Complete | Honestly partial | Failed/crashed |
|---|---:|---:|---:|---:|
| Quick | 27 | 8 | 19 | 0 |
| Maximum | 27 | 8 | 19 | 0 |

Partial is not failure: every partial artifact states what was analyzed, what was skipped, and why. Common reasons are optional provider failures, file limits, relationship limits, and product-intelligence limits. Provider failures remained isolated. Maximum recorded 10 TypeScript-semantic provider failures and one Syft failure without aborting repository scans.

## Accuracy

Eight repositories received actual manual scorecards with all 14 dimensions, trace/deletion/blast-radius probes, trust answers, time to first insight, and written observations. The reviewed sample covers conventional full-stack apps, a small React client, Express, FastAPI, Supabase/Stripe, an AI chat app, a documentation index, and the weakest polyglot microservices case.

The Alpha failure database contains 31 reproduced findings: 27 fixed with regression evidence and four accepted limitations. The fixed set includes one P0, 15 P1, and 11 P2 issues. There are zero open or confirmed P0/P1/P2/P3 entries. “Accepted limitation” is not counted as fixed or silently closed.

The strongest behavior is conservative first-pass understanding on conventional Next.js/Supabase, Express, and FastAPI applications: framework, route, auth, data, and external-service boundaries became understandable without executing the code. Exact-path deletion answers now distinguish proven use, possible use, and no detected use without saying “safe to delete.”

## False positives

The most important false-positive classes were evidence-boundary leaks:

- lockfiles, comments, docs, detector source, tests, specifications, generators, and support scripts being mistaken for runtime behavior;
- generic words such as `registration`, `apiVersion`, or `Select ... from` being promoted into features, infrastructure, or SQL tables;
- package presence being treated as external-service use;
- framework identity inferred from incidental vocabulary instead of direct runtime evidence;
- duplicate finding cards overwhelming the first screen.

Fixes were general policy or parser changes with deterministic regressions, never repository-name exceptions.

## False negatives

The most meaningful remaining misses are:

- React CRUD actions do not yet combine cleanly with a shared remote client into an end-to-end behavior summary;
- bounded traces often find the right entry but do not reserve enough hop budget for terminal tables/services;
- the Google microservices demo is not reconstructed as a comprehensible polyglot Go/gRPC service topology;
- framework/library repositories and huge monorepos reach file, graph, or enrichment bounds.

These are disclosed in ALPHA-022 through ALPHA-025 rather than papered over with guessed relationships.

## Confidence

The selected calibration contains 25 claims across 10 pinned repositories, including six pre-fix wrong claims. It is a bug-finding sample, not a random sample and not statistically significant. Strong claims were wrong when non-runtime evidence leaked across boundaries; likely external-service claims were especially fragile. After those fixes, none of the 19 resampled claims was manually disproved, but that is regression evidence rather than a precision estimate.

Confirmed remains reserved for independent evidence agreement. Strong requires runtime-path plus graph/route corroboration. Likely service claims remain review prompts. The product must continue to say when it cannot prove a relationship statically.

## Human usefulness

Primary answer across eight reviewed repositories:

- Yes: 5
- Somewhat: 2
- No: 1

Trust before an AI coding change was generally “Mostly,” not unconditional. The microservices demo received No/No because a correct minority Flask view was not a useful model of the overall system. Approximate import-to-first-insight times are recorded in each reviewed scorecard; they are manual observations, not telemetry.

## Best insights

- In the Supabase payments app, the Stripe webhook and Supabase admin client—not the checkout button—form the consistency boundary.
- In the Vercel AI chatbot, the “chat app” is also a document, history, voting, upload, API, and persistence system.
- In the Express RealWorld API, social follows/favorites live in Prisma join tables behind route vocabulary.
- In the FastAPI app, admin behavior spans a frontend route and protected backend user endpoints rather than one admin module.
- In the RealWorld index, the repository documents implementations but is not itself one of those runtime applications.

The observed value clusters around UNDERSTANDING, ARCHITECTURE, and RISK. There is not enough external-user evidence to change positioning or branding.

## Worst failures

- P0: a duplicate route filename could cause a deletion answer to cite the wrong file. Exact-path-first resolution and a regression fixed it.
- P1: a Radix UI `Select` import became a fake SQL table. Language-syntax exclusion and a real-repository rescan fixed it.
- P1: specs, generators, lockfiles, comments, and support material created confident fake runtime features/services. Runtime evidence policy now excludes these sources.
- P1 accepted: the polyglot microservices architecture remains materially incomplete.
- P2 accepted: relevant traces can still have weak terminal-effect ordering.

## Security

Maximum-profile machine artifacts recorded 3,446 structural/security/dependency findings across 13,678 package observations. These are candidates from multiple analyzers, not 3,446 confirmed vulnerabilities, and high counts on large workspaces demonstrate why finding count is not a success metric.

The final security gate includes the V3 security fixtures, Gitleaks against the WTFCode worktree, secret-redaction assertions in Change Guard, and the normal analyzer isolation boundary. A live browser cross-user probe requested another user's project ID and received HTTP 404. The corpus itself was never executed. No source code analytics or invasive telemetry was added.

## Performance

- Quick: median 2.81s, range 0.40–20.03s. Six repositories exceeded 2× median. Adminer, Django, and Drizzle reached at least 110 MiB.
- Maximum: median 3.98s, range 0.13–69.63s. Eight repositories exceeded 2× median. Adminer, Django, Drizzle, and Turborepo reached at least 110 MiB.
- Django completed partial at about 126 MiB under the 128 MiB worker limit and remains an accepted memory-margin limitation.
- All large runs completed without a process-level corpus failure. The per-run table is in `performance.md`.

Cache regressions cover clean reuse plus dirty-worktree, new-commit, provider-version, profile, and configuration invalidation. Correct invalidation is treated as more important than a faster stale answer.

## Regression hardening

Alpha fixes added or expanded regressions for runtime evidence filtering, feature/service confidence, specs/generators/support paths, bounded semantic workers, Windows process isolation, resume schema identity, provider/config/profile cache identity, exact-path deletion, Express/Auth.js recognition, first-screen deduplication, SQL import exclusion, mobile trace containment, and scope-drift wording/classification.

The detailed 31-entry failure database records category, severity, root cause, fix commit, regression, and status. The change-intelligence report validates real commit pairs and a disposable Before/After workflow.

## Remaining limitations

- Polyglot microservice and monorepo service-boundary synthesis is not yet trustworthy enough for unsupervised use.
- Trace relevance and terminal effects need improvement even when starts are correct.
- React CRUD plus shared-client behavior remains under-explained.
- Django and several large repositories operate near resource limits or truncate graphs/features.
- Security and dependency findings need human triage; totals are not confirmed vulnerability counts.
- Static analysis cannot prove dynamic dispatch, runtime configuration, generated behavior, or production deployment state.
- Only eight scorecards were manually reviewed, and no external users have yet supplied independent feedback.
- Evidence interaction was manually inspected during browser acceptance; no analytics were introduced, so there is no click-rate claim.

## Exit-criteria judgment

The 25-repository threshold, diversity, zero unresolved P0, isolated providers, partial disclosure, meaningful review sample, majority-useful result, secret checks, and cross-user isolation criteria are met. “Very few unresolved P1” is met only under the explicitly narrowed supervised-Alpha recommendation: one P1 architecture limitation remains accepted and visible.

The next action should be a 5–10-person supervised Alpha using `tests/alpha/feedback.md`, with emphasis on whether users inspect evidence and whether WTFCode changes what they test before prompting an AI coding agent. No billing, team infrastructure, autonomous edits, or V4 expansion is justified by this evidence.
