# WTFCode 3.0 pilot readiness

**Assessment: local preparation verified; external pilot blocked.** Updated 29 October 2026. No real participants were invited, and no cloud deployment, merge, live email, paid service, production data change or billing activation occurred.

Read the launch checklist, deployment guide, five-person research script and machine-readable verification in this directory. Eight genuine workflow screenshots are indexed in `docs/PILOT-SCREENSHOTS.md`.

## Baseline and priorities

The separate review worktree/branch codex/pilot-launch-readiness starts at UX 2.0 f873f83bc77fe9242c880a30967fbd3cabac6bda, descended from MVP d399e8ec91e6b03485154079c6b51f3961d31322. A fresh fetch confirmed these heads. [PR #3](https://github.com/flegardev/WTFCode/pull/3) is still draft, open and unmerged; its [baseline CI](https://github.com/flegardev/WTFCode/actions/runs/40732009225) passed. This pilot PR is stacked on the UX branch.

Existing audit, staging, deployment and UX reports, application/tests, CI and the 23 screenshot evidence were inspected. Original branches/checkouts/screenshots are preserved. Durable leases, owner scoping, static-analysis isolation and trial accounting already existed and were verified rather than rebuilt. There was no major redesign.

P0: lifecycle, controlled access, worker/deletion fences, monitoring, recovery and deployment controls. P1: minimal feedback, onboarding copy and usability preparation. P2: billing, private repositories, teams, paid AI and elaborate analytics remain unavailable.

## Changes and bugs fixed

- Closed/allowlisted admission, maximum five approved non-admin accounts, required verification and server-enforced Quick pilot scans. Trial remains one public project and five durable jobs per rolling 30 days; failures count, retries share a job, and browser fields never grant a paid plan.
- Random 256-bit purpose-bound account tokens, digest-only database storage, 24-hour verification / 30-minute recovery expiry, single use, resend invalidation, CSRF consumption and durable rate limits. Public requests queue delivery without account lookup/provider timing. Existing/ineligible signup and link requests use generic responses.
- Fragment-based links avoid sending the raw token in HTTP access URLs; GET does not consume it. Browser testing found that a new link on the same token page could retain the old code. Hash-change handling now replaces it, clears the fragment and removes the stale error; its regression passed.
- Password reset updates the password/session epoch, removes indexed sessions and fences late writes. Old payloads fail the epoch check at the next authenticated request. Scan-status polling now refreshes identity and enforces verification/access policy. Admin bootstrap requires a verified address.
- Authenticated, CSRF-protected account export derives the owner from the session and ignores supplied user IDs. It exports stored account/project/file/scan/finding/feedback metadata, excludes credentials and marks truncation. It does not promise a complete source archive.
- Deletion requires the current password and exact DELETE confirmation. Cascades remove account/project/evidence/job/installation-link/token/session/feedback rows. Durable project-ID-only cleanup markers and orphan detection cover separate worker storage and late files. A post-clone heartbeat and existing lease/FK fences prevent stale jobs from repopulating deleted data.
- The legacy provider accelerator had no ownership relationship. Closed/allowlisted operation now bypasses it and maintenance/deletion invalidates expendable database/filesystem cache entries. Authoritative scan results remain. Re-enabling shared caching requires a separate ownership review.
- Idle/busy heartbeat, private queue/failure/completion/duration/disk/mail/cleanup/feedback snapshot, worker health probe, maintenance and graceful signals. Pilot leases are bounded to five minutes between checkpoints; three attempts and backoff remain.
- Conservative GitHub size preflight, in addition to existing post-clone 100 MB limits, source/process limits and proposed disk caps. Cleanup does not traverse a checkout-root symlink. An additive RLS/grants migration, idempotent scoped upgrade command, Compose overlay, approved SMTP handoff, encrypted backup tool and operational/rollback runbook were prepared.
- Optional feedback stores category, area, usefulness rating and time with the user, after its data contract was written. It accepts no free text, source or credentials, and adds no third-party tracker. Export/deletion and scheduled 30-day cleanup include it.
- The empty dashboard now has one primary import CTA while retaining sidebar navigation. “UX Review” was a disposable account's actual name, not hardcoded development navigation. Existing source-backed summaries and Change Impact uncertainty wording were retained.

## Actual tests

Final local exits: zero for Composer validation, PHP syntax, PHPUnit, PHPStan, scoped formatting, JavaScript syntax and every applicable tier. **36 PHPUnit tests / 96 assertions; 10 portable, 3 analyzer, 9 PostgreSQL integration, 2 security and 2 import/worker E2E scripts.** Composer found no advisories and npm reported zero known lockfile vulnerabilities. These Composer/npm results are separate from the container audit below and do not establish security certification.

Lifecycle coverage includes both expiries, single use, resend invalidation, duplicate signup/password preservation, admission/profile limits, rate limiting, session revocation/late writes, owner export, running-job deletion and legacy-cache invalidation. The symlink-root fixture was explicitly skipped on Windows because this account cannot create symlinks; Linux CI includes it without ignoring failures.

A real browser registered, verified and signed in a disposable account, imported the RealWorld Node/Express public repository, waited for real worker completion, asked a deterministic authentication question with **40 evidence references**, followed a source explanation, inspected Change Impact and the dependency graph, checked the project limit, requested/reset the password, rejected the old session, signed in again, downloaded an export and deleted the account with queued work.

A second verified account received 404 across six foreign-project endpoints and could not forge an export owner. SQL/filesystem checks found zero remaining pilot accounts, pending job, project/file/feedback records or checkout paths. Scan-budget/coalescing tests cover the five-job cap and rolling window. No customer data was used.

Project/map/account screens fit 1440, 390 and 320 px. Both themes and keyboard focus were checked. Axe reported zero A/AA violations across 16 changed-page/theme/width states and no recorded browser page errors. This does not replace manual assistive-technology or five-person usability work.

A real worker child was killed after claiming durable work; a replacement completed a real public scan. Only disposable lease/backoff timestamps were advanced instead of waiting for expiry; production timing was not relaxed. A real unavailable Git remote was attempted three times and ended with a safe error; GitHub metadata rejected torvalds/linux before cloning. Existing process deadline/provider failure regressions passed. The missing-remote fixture models a source becoming unavailable after queueing; no third-party public repository was deleted.

Stopping the independent database changed public health **200 → 503**, without traces/credentials, and the worker exited **1**. Database/web restart plus a replacement worker recovered. The browser reopened the persisted account/project before deletion. Windows crash recovery initially hit a sharing violation on an open log inside PGDATA; moving the log outside PGDATA fixed it without disabling host protection. PHP's missing trusted CA bundle was configured from Windows trusted roots; GitHub HTTPS then returned 200 with certificate verification enabled.

Backend-only security checks covered **28 public tables × 3 browser-facing roles × 4 DML privileges**, plus RLS and idempotent upgrade. Hosted Supabase advisors, actual remote role permissions and TLS need checking on the approved project.

## Recovery and measurements

A real **201,300-byte encrypted backup** restored into a distinct empty local database: one account, one project, one scan, 57 file records and two schema versions. Row fingerprints and the restored password matched. A wrong key and a nonempty target were refused. The 0.943-second local safety/restore sequence is not a hosted recovery forecast. The disposable key was discarded afterward; no live database was overwritten.

The proposed daily encrypted off-host schedule and seven-day retention are not installed. They imply up to 24 hours' data loss; one-hour recovery is a planning target, not a tested SLA. Restores require deletion reconciliation and session/token invalidation before service resumes. Delivered mail cannot be recalled. Worker cleanup/retention stops while it is offline.

| Real Quick scan, shared cache bypassed | Import to result | PHP allocation peak | End checkout including Git | Stored project row bytes |
| --- | --- | --- | --- | --- |
| octocat/Hello-World | 1.363 s | 8 MiB | 29,536 B | 2,541 B |
| RealWorld Node/Express example | 4.550 s | 14 MiB | 797,278 B | 1,784,322 B |

Commits and measurement detail are in the JSON. Duration includes API/clone/analysis. PHP allocation excludes Node children, OS RSS and container peaks. Disk was measured at scan end, not sampled at peak. Row bytes exclude indexes, TOAST and full physical allocation. These are individual samples, not a load/hostile-input benchmark. A separate production-mode real public scan verified temporary checkout removal on disposable data.

## Pricing hypotheses and workload

Published Render/Supabase prices checked 28 October imply a candidate base of approximately **$57/month**: $7 web + $25 worker + $25 first Pro database project. Tax, currency conversion, workspace/seat fees, overages, email and separate backup storage are additional; an existing organization/additional database can change the total. See [Render pricing](https://render.com/pricing?trk=public_profile__reactions-text) and [Supabase pricing](https://supabase.com/pricing). Recheck invoice previews; no purchase is authorized.

At $25/month, the 4.55-second sample allocates roughly $0.000044 of 30-day worker clock capacity. Idle time is billed: at 25 jobs/month the full worker allocation is $1/job and the $57 base stack is $2.28/job, or $11.40 per five-user seat, before support and extras. Three attempts can triple active work. The 1.8 MB stored-row sample is a lower bound; reserve room for histories/indexes/TOAST and measure provider storage for representative repositories. Fixed database/support costs dominate initial marginal storage.

| Plan hypothesis | Proposed usage | Actual state |
| --- | --- | --- |
| Free €0 | 1 project, 5 jobs / rolling 30 days, Quick, local questions | Enabled with server-owned accounting |
| Founder €19/month | Candidate 3 projects / 20 jobs, terms to validate | Not sold/granted; no durable paid entitlement implementation |
| One-time €9 | Candidate 1 project / 1 completed report | Not sold/granted |

Five half-hour onboardings already consume 2.5 hours before support. Use the research plan's 20-hour weekly allocation and validate burden/value rather than assuming profitability. Deletion/re-registration starts a new trial identity: this is not a cross-identity commercial anti-abuse ledger. The founder controls five approved addresses and should remove withdrawn addresses. Resolve durable billing identity/usage and privacy retention before selling plans.

Future billing needs approved seller identity, invoicing/VAT/cancellation/refund terms, provider approval, authenticated/idempotent webhooks, durable entitlements, reconciliation and upgrade/downgrade/failed-payment tests. No payment collection was activated.

## Remaining blockers

**Do not invite external users yet.** Review the residual vendor-deferred image advisories and repeat load/resource/restart checks on the approved provider; verify HTTPS/proxy cookies and database certificates; approve SMTP and test real delivery; configure off-host backup/deletion reconciliation and alert notifications; supply operator identity/support/privacy contact and reviewed notices.

The MTA adapter/file mailbox is not proof of live delivery. Cloud cgroup/tmpfs behavior and controlled hosted network-timeout/remote-removal fault injection remain incomplete. README-only/unsupported-file and bounded subprocess/provider fixtures do not exhaustively establish hostile-input safety. A recent heartbeat can outlive a hard-killed process for five minutes; unhealthy Docker state alone does not restart a hung process. One worker can delay account messages behind analysis. Keep concurrency/profile/source limits and monitor the queue.

Five-person usability, repeat-use and willingness-to-pay evidence remains uncollected. There are no fabricated participants, testimonials or business metrics.

## Slovenian questions requiring qualified advice

These are questions, not a compliance opinion or permission to operate through a family member's business.

- Who is the contracting seller/operator, who owns/licenses the software, and what arrangement permits the founder's work, student status, remuneration and contributions? [SPOT short-term family work](https://spot.gov.si/sl/teme/kratkotrajno-delo/) describes unpaid work capped at 40 hours/month under specific conditions. Ask how that relates to a planned 20-hour/week commitment; do not assume eligibility.
- What invoice records, tax, VAT registration/place-of-supply and possible EU consumer OSS obligations apply to subscriptions and one-time digital services? Ask an accountant using actual seller/customer locations; start with [FURS VAT guidance](https://www.fu.gov.si/davki_in_druge_dajatve/podrocja/davek_na_dodano_vrednost_ddv/).
- What pre-contract, digital-service conformity, withdrawal/cancellation, refund, complaint and renewal disclosures apply, including a free pilot using personal data? Have counsel review the actual terms/UX against [SPOT online-sales guidance](https://spot.gov.si/sl/teme/spletna-prodaja).
- Who is controller/processor for accounts, repository evidence and feedback? Confirm legal basis, notices, rights/breach process, retention, DPAs/subprocessors and transfers using [Information Commissioner guidance](https://www.ip-rs.si/varstvo-osebnih-podatkov/klju%C4%8Dna-podro%C4%8Dja-uredbe/pogodbena-obdelava). Public source may still contain personal data/secrets; public availability is not blanket authorization for every use.

## Handoff

Review the stacked PRs, then approve hosting/email/identity/retention decisions in the checklist. Review-branch Vercel auto-deploy suppression includes this branch. No automatic merge/deployment is part of this work.

[Draft pilot PR #4](https://github.com/flegardev/WTFCode/pull/4) is open against the unmerged UX branch. [Pilot CI run 40851468254](https://github.com/flegardev/WTFCode/actions/runs/40851468254) passed both jobs on 7f909dd, including PostgreSQL 17/PHP 8.3, idempotent upgrade, Compose configuration validation, the complete matrix and real checkout cleanup. Its Linux log confirms the symlink-root cleanup fixture passed and preserved its external target. This resolves the Windows-specific test limitation; subsequent actual container evidence is recorded below. Both connected Vercel projects returned zero deployments for the pilot branch after pushing.



## Subsequent container verification and runtime remediation

[CI run 40855049669](https://github.com/flegardev/WTFCode/actions/runs/40855049669) passed all three jobs on 64a7093. It built the actual image and booted loopback-only web, PostgreSQL and separately supervised worker containers. Closed registration, real Quick scans, export/deletion, production checkout cleanup and worker health passed. Hello-World correctly has no analyzable source (only an extensionless README); RealWorld produced 57 file records. Container import-to-result times were 3.102 and 6.731 seconds, including worker polling. All disposable services/volumes were removed.

The worker's 2 GB, 1 CPU and 128-PID limits were observed. Its cgroup memory peak before the intentional disk probe was **1,364,070,400 bytes (about 1.27 GiB)**, including processes and page cache, unlike the native PHP-only allocation figure. ENOSPC occurred at 536,870,912 bytes and the probe file was removed. This supports the 2 GB worker budget and rejects a 512 MB worker for the measured case. It does not prove maximum-source/concurrent-user capacity on a cloud plan.

The first image audit reported 36 Critical/253 High raw matches, including 106 fixable High/Critical matches. Updating Node to 24.22.1, using the supported Trixie base and excluding unused advanced analyzer binaries from the default Quick-only image reduced this to **zero fixable High/Critical matches**. The full image inventory still reports **19 Critical and 144 High matches** in vendor-deferred/unfixed dependencies. These are scanner/package matches, not 163 demonstrated application exploits. They remain an explicit release-review gate; no ignore list or finding suppression was added. See [the retained before/after findings and primary-source review](PILOT-IMAGE-AUDIT.md). The workflow now rejects fixable High/Critical runtime findings while retaining all other results for review.

The new container fixture initially assumed every repository contained source and a shell pipeline failed to propagate that error. The fixture now explicitly distinguishes README-only from source-bearing repositories, and the workflow uses Bash pipefail. A Docker image-reference error in the audit command was also corrected. Failing evidence was retained rather than treated as a successful scan. These were test-harness corrections, not removal of coverage.
