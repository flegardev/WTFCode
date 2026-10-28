# WTFCode UX 2.0 implementation and verification

The review branch makes the existing PHP product easier to understand while preserving the scanner, database schema and security boundaries. It starts from verified SaaS MVP commit `d399e8ec91e6b03485154079c6b51f3961d31322`. No deployment, billing, private repository release or external AI service was activated.

[Before/after gallery](UX-V2-COMPARISON.md) · [Scrollable HTML gallery](UX-V2-COMPARISON.html) · [23 genuine screenshots](UX-V2-SCREENSHOTS.md) · [Machine-readable evidence](UX-V2-VERIFICATION.json) · [Initial audit and plan](UX-V2-PLAN.md)

## What changed

- **Project understanding:** the opening explains detected source structure in ordinary language, without claiming a business purpose the scanner cannot establish. The sequence is “What did I build?”, “How does it work?”, “What should I know?” and “What should I do next?”. Counts, stack details, high-impact files and exploration history remain available behind a disclosure. One review lead is visible with a link to all findings.
- **Ask Codebase:** deterministic answers lead with relevant request handlers or named source matches. Four source cards link to owner-scoped file evidence and line context; remaining references and symbols expand separately. Copy, loading, retry, keyboard focus return and tab containment work. On mobile, the answered question becomes a compact editable heading, bringing the answer forward. Full-page server-rendered questions remain available without the drawer.
- **Change Impact:** intent comes first, followed by an explicitly required concrete target. The interface explains that intent guides the review prompt while the target determines analysis. Results show connected code, certainty categories, a checklist and source links. The editable request collapses after submission. More files, connection pairs, tests, routes, boundaries, the heuristic priority and the implementation prompt remain available. Unmatched targets explain uncertainty instead of implying safety.
- **Graphs:** a compact shared system diagram provides fit/zoom/pan, readable labels, text selection buttons, explanations and evidence links. All architecture connections remain labeled inferred. Mobile opens the visual diagram on demand and lays out connected parts together. Advanced symbol traversal, grouping, filtering, confidence thresholds and path tracing remain available. Filtering uses the filtered collection for layout rather than a stale visibility calculation; superseded layouts stop. Small file graphs use a grid with larger basename labels and retain full paths in evidence.
- **Dashboard and navigation:** a consistent workspace rail becomes compact mobile navigation. Project cards lead with a source-backed summary, a review lead and “Explore project”. Grouped review themes now agree with the overview; raw finding instances no longer appear as an equivalent count. Empty state offers the requested first-project action and a real public example. Settings exposes working usage, theme guidance and privacy information.
- **Landing and pricing:** the exact requested headline, support message and CTAs accompany an interactive five-tab saved scan. Overview, architecture, import dependencies, question and change result all come from a verified public repository. No anonymous scan endpoint was added. Dependency examples are ranked by source-file mentions; they do not claim installed versions or vulnerabilities. Prices remain unvalidated proposals and unavailable plans have no checkout action.
- **Source explanation:** the basename and plain explanation precede why the file matters and what to check before editing. Imports, direct/transitive consumers, real route declarations and the symbol outline are disclosed separately. Clicking a question reference opens the evidence section with its requested location. Full source text is not retained; the page says so.

## Correctness and UI bugs fixed

1. An apparent impact chain previously joined the first direct dependent to an independently reachable transitive symbol. That continuation was not established. Displayed pairs now preserve an actual direct edge, its direction, IDs and confidence; repeated pairs are deduplicated. A database regression checks the pair against stored graph relationships.
2. Internal “normalized evidence” counts led question answers. They now follow a bounded plain explanation instead of substituting for one.
3. Question evidence was non-clickable. Links now resolve scanned paths to file IDs within the authenticated project; unknown locations remain unlinked. The frontend also checks the source link’s origin and route.
4. The dashboard compared raw finding instances with grouped themes. Both main views now use the same grouping and label.
5. Button states could inherit incompatible text/background colors. Explicit states and simultaneous theme color changes fix the dark dashboard hover case, including the transient theme-switch case. A separate genuine hover screenshot records it.
6. Legacy light-theme text overrides, a missing graph role and an unlabeled prompt field were found by auditing expanded sections and corrected.
7. Small-screen navigation overflow and an unnecessary minimum body width were corrected. The mobile diagram now relayouts after becoming visible; text and nodes do not overlap in the tested fixture.
8. A source-link variable shadow, native-dialog tab escape, a collapsed-form nesting error and a grid option error were caught during implementation and corrected before the final evidence run. Earlier failed runs are not reported as passes.

## Design system and accessibility

The implementation retains server-rendered PHP and the pinned Cytoscape library. Shared tokens define navy/violet colors, light surfaces, typography, borders, control radii and spacing. Shared templates provide navigation and the architecture diagram. No frontend framework or production npm dependency was added. Local Lucide 0.468.0 SVGs use an allowlist; the supplied ISC license and Feather notices are retained in `public/assets/icons/LICENSE`.

Controls have explicit hover, focus, active and disabled states. Primary controls use 44px minimum targets; inline text links retain their inline semantics. Focus outlines, the existing skip link, native disclosures/dialog semantics and reduced-motion support remain active. Preview tabs support arrows, Home and End. Graph systems have keyboard-readable buttons and evidence links; advanced symbol text indexing remains available.

**Final axe-core 4.10.3 audit: 42 page/theme/expanded/mobile-drawer states, zero automated WCAG A/AA rule violations.** These checks cover WCAG 2.0/2.1/2.2 A/AA tags, not full accessibility certification. Canvas relationships were additionally inspected visually and exercised through their text/control alternatives. Real assistive-technology users and additional browser engines still need evaluation.

## Verification actually performed

A fresh isolated PostgreSQL database was initialized from the canonical schema. A disposable account registered through the real browser, imported `gothinkster/node-express-realworld-example-app`, and a real durable worker completed five analyzer engines at commit `30b68e1e881462b2f4164ea09ab4c4f5699c7b0b`. The scan stored 57 readable files, 172 symbols and eight routes. No repository code or dependency installation was executed.

| Check | Result |
| --- | --- |
| `composer verify` | Pass: PHP syntax for 197 files; PHPUnit; portable scripts; PHPStan; scoped formatting |
| PHPUnit | 36 tests, 96 assertions passed |
| Portable tier | 10 scripts passed |
| Analyzer contracts | 3 scripts passed |
| Database integration | 8 scripts passed; direct-edge regression also rerun after its addition |
| Security / PostgreSQL production contracts | 2 scripts passed |
| Public GitHub import E2E | 1 script passed |
| Production-mode hosted checkout cleanup, disposable localhost only | Passed, real public import/worker/evidence/cleanup |
| Worker and UI JavaScript syntax | Passed, including new preview and browser scripts |
| Existing browser acceptance | 41 checks passed: live Q&A, CSRF, a second account, ownership boundaries, themes and navigation |
| Expanded responsive matrix | 331 checks passed across 154 authenticated page/theme/width combinations |
| Public preview and interaction checks | 215 checks passed, including 42 public page/width/theme visits, 70 preview tab states, expanded views, immediate theme/hover contrast, copy/retry, keyboard containment, reduced motion and graph readability |
| Additional source ownership checks | File and symbol requests from the empty account return 404 |
| axe audit | 42 states, zero reported violations |
| Screenshot review | 23 genuine new images reviewed; original 14 unchanged |

All seven requested widths were tested in both themes: **320, 375, 390, 430, 768, 1024 and 1440 px**. Final checks found no horizontal page overflow. An intentional 503 test verifies the retry UI; the successful retry calls the real deterministic endpoint. No screenshot presents that injected failure as an actual product outage.

Repeated capture runs reached registration throttling. The final capture reuses an existing authenticated disposable empty account; throttling was not bypassed or relaxed. The original browser acceptance has a deterministic dark-theme setup so both theme transitions are tested regardless of previous browser state.

## Screenshots and measurements

The [Markdown gallery](UX-V2-COMPARISON.md) provides 14 paired comparisons directly reviewable in GitHub. The [HTML gallery](UX-V2-COMPARISON.html) uses the same original PNGs with independently scrollable columns. Its 28 image paths were checked. Automatic approval review rejected starting its local preview server with “blocked by policy”; no browser rendering claim is made for that HTML document. The application images themselves were captured and inspected in the real running browser.

Matched-width mobile captures show default-page height falling from **4,291 to 2,706 px (36.9%)** for the overview and **3,999 to 2,569 px (35.8%)** for Change Impact. Technical sections remain expandable. These are screenshot dimensions, not evidence of faster human task completion.

Fourteen local anonymous landing navigations measured **77.8ms median / 181ms p95** navigation duration and about **150KB decoded resource content**. The measurement is warm/local, uses no network or CPU throttling and is not a production performance promise. The landing preview reads a saved public JSON export, requires no user database query and makes no external AI request. No hosted load test or large-customer benchmark was run.

## Product quality assessment

| Perspective | Evidence-backed assessment | Still unvalidated |
| --- | --- | --- |
| Beginner | The first view explains source structure and presents useful next actions; answers and change results lead with explanations. | Thirty-second comprehension and task success with actual beginners. The deterministic summary does not reliably establish a business purpose. |
| Developer | Source locations, confidence, static limitations and advanced exploration remain available. Direct impact pairs are checked against stored edges. | Runtime completeness, unsupported frameworks and larger real projects. |
| Potential customer | The interface makes repeated repository understanding and pre-change review easier to inspect. | Recurring demand, retention, willingness to pay and all proposed price/plan boundaries. No customer research was conducted. |

## Preserved systems and boundaries

Registration/login, session storage and throttling, CSRF, per-user authorization, repository ownership, trial entitlements, durable queue/lease recovery, isolated workers, sanitization and temporary checkout cleanup remain in place. The canonical database schema is unchanged. Private GitHub imports remain gated off. Customer Q&A remains deterministic even when optional provider adapters exist. Git diff, Change Guard, source/symbol directories, routes, tables, feature tracing, safe prompts and learning history remain reachable through contextual tools or direct links.

`vercel.json` disables automatic deployment specifically for `codex/premium-ux-v2`, using Vercel’s documented [branch deployment setting](https://vercel.com/docs/project-configuration/git-configuration). It does not change other branch policies. No provider settings, production data, billing or private-access gates were changed. The work is for review against `codex/saas-mvp`; merging/deployment require a separate decision.

## Remaining limitations and launch priorities

1. **Before a public commercial launch:** implement and verify account recovery, email verification, account deletion/export, published retention/support/operator details, backup/restore rehearsal and hosted worker monitoring/capacity. Existing launch gates in [STAGING-AND-LAUNCH.md](STAGING-AND-LAUNCH.md) still apply. A successful frontend build does not establish backend availability.
2. **Before private access:** use a dedicated staging GitHub App to verify installation ownership, revocation, repository scope, isolation and retention; keep the release gate disabled until then.
3. **Before charging:** validate demand and trial limits with real users, define durable paid entitlements and retention economics, then implement and test actual billing with explicit authorization. Proposed prices remain hypotheses.
4. **Analysis limits:** static evidence can miss dynamic dispatch, generated code, framework conventions and external infrastructure. Roles and architecture integration patterns remain heuristic. Change analysis is bounded to 20 seed symbols, six hops per seed and 150 displayed files. Quick-scan import examples are not an installed dependency inventory or vulnerability audit. Source links show stored evidence, not a retained full-code viewer.
5. **UX breadth:** evaluate Safari/Firefox and real assistive technology; benchmark larger codebases and validate the explanations with beginners. Some legacy styling still supports advanced tools; it has not been comprehensively rewritten. Dashboard summaries currently add bounded per-project reads; revisit batching before substantially larger paid workspaces.
6. **Infrastructure verification:** Docker remains unavailable in this Windows environment. No container build, cloud release, hosted incident drill, production load test, paid AI activation or checkout was performed.

**Readiness:** suitable for code review and a controlled public-repository pilot after the existing operating gates are satisfied. This work does not establish commercial launch readiness or validated paid demand.

## Reproduce the UI evidence

Use an isolated fresh local database and the staging instructions. Keep deterministic explanations and private imports disabled. The browser scripts deliberately target the documented public sample as project ID 1 and its scanned authentication controller as file ID 25; prepare that fixture in a fresh database rather than pointing them at customer data.

With Playwright CLI 0.1.22 available, open the app, register the sample account, import the public URL and run `php tools/scan-worker.php --once`. Register a separate empty disposable account once, sign in through the browser and save its real state to ignored `output/ux-v2-empty-session.json` for capture reuse. Do not disable throttling for repeated captures. Install axe-core 4.10.3 locally under ignored `output/axe/package/axe.min.js`; the audit intercepts only its same-origin test-script URL and leaves the application CSP unchanged.

From the repository root, in the signed-in sample session, run the CLI’s `run-code --filename=` with `tools/browser-acceptance.js`, `tools/browser-ux-v2.js`, `tools/browser-ux-v2-interactions.js`, `tools/browser-ux-v2-accessibility.js` and `tools/capture-ux-v2.js`. The audit and interaction runs return structured results. Screenshots go to `docs/screenshots-v2/`; the original baseline folder is not written. The saved public preview can be regenerated with `php tools/export-public-example.php 1`; the CLI exporter refuses unrelated repositories and installation-backed imports.

## File inventory

The following inventory covers this review’s source, assets, tests and evidence. Individual screenshot dimensions and hashes are in the verification manifest.
- [docs/UX-V2-COMPARISON.html](../docs/UX-V2-COMPARISON.html)
- [docs/UX-V2-COMPARISON.md](../docs/UX-V2-COMPARISON.md)
- [docs/UX-V2-PLAN.md](../docs/UX-V2-PLAN.md)
- [docs/UX-V2-REPORT.md](../docs/UX-V2-REPORT.md)
- [docs/UX-V2-SCREENSHOTS.md](../docs/UX-V2-SCREENSHOTS.md)
- [docs/UX-V2-VERIFICATION.json](../docs/UX-V2-VERIFICATION.json)
- [package.json](../package.json)
- [public/ask.php](../public/ask.php)
- [public/assets/css/saas.css](../public/assets/css/saas.css)
- [public/assets/data/public-example.json](../public/assets/data/public-example.json)
- [public/assets/icons/file-code-2.svg](../public/assets/icons/file-code-2.svg)
- [public/assets/icons/folder-git-2.svg](../public/assets/icons/folder-git-2.svg)
- [public/assets/icons/git-branch.svg](../public/assets/icons/git-branch.svg)
- [public/assets/icons/LICENSE](../public/assets/icons/LICENSE)
- [public/assets/icons/message-circle.svg](../public/assets/icons/message-circle.svg)
- [public/assets/icons/network.svg](../public/assets/icons/network.svg)
- [public/assets/icons/plus.svg](../public/assets/icons/plus.svg)
- [public/assets/icons/settings.svg](../public/assets/icons/settings.svg)
- [public/assets/js/change-workbench.js](../public/assets/js/change-workbench.js)
- [public/assets/js/overview-graph.js](../public/assets/js/overview-graph.js)
- [public/assets/js/project-shell.js](../public/assets/js/project-shell.js)
- [public/assets/js/public-example.js](../public/assets/js/public-example.js)
- [public/assets/js/symbol-map.js](../public/assets/js/symbol-map.js)
- [public/change.php](../public/change.php)
- [public/dashboard.php](../public/dashboard.php)
- [public/example.php](../public/example.php)
- [public/file.php](../public/file.php)
- [public/index.php](../public/index.php)
- [public/map.php](../public/map.php)
- [public/pricing.php](../public/pricing.php)
- [public/project.php](../public/project.php)
- [public/settings.php](../public/settings.php)
- [src/Application/ChangeImpactService.php](../src/Application/ChangeImpactService.php)
- [src/Application/ProjectUnderstanding.php](../src/Application/ProjectUnderstanding.php)
- [src/ExplanationService.php](../src/ExplanationService.php)
- [src/helpers.php](../src/helpers.php)
- [tests/Unit/ProjectUnderstandingTest.php](../tests/Unit/ProjectUnderstandingTest.php)
- [tests/V3ChangeTest.php](../tests/V3ChangeTest.php)
- [tests/V3ModesTest.php](../tests/V3ModesTest.php)
- [tools/browser-acceptance.js](../tools/browser-acceptance.js)
- [tools/browser-ux-v2-accessibility.js](../tools/browser-ux-v2-accessibility.js)
- [tools/browser-ux-v2-interactions.js](../tools/browser-ux-v2-interactions.js)
- [tools/browser-ux-v2.js](../tools/browser-ux-v2.js)
- [tools/capture-ux-v2.js](../tools/capture-ux-v2.js)
- [tools/export-public-example.php](../tools/export-public-example.php)
- [vercel.json](../vercel.json)
- [views/architecture-diagram.php](../views/architecture-diagram.php)
- [views/ask-panel.php](../views/ask-panel.php)
- [views/footer.php](../views/footer.php)
- [views/header.php](../views/header.php)
- [views/project-nav.php](../views/project-nav.php)
- [views/public-example.php](../views/public-example.php)
- `docs/screenshots-v2/*.png`: 23 actual browser captures, individually indexed above.
