# Pilot launch checklist

Assessment: LOCAL CONTROLS VERIFIED; EXTERNAL PILOT BLOCKED. Updated 29 October 2026. “Ready” below describes verified preparation, not a hosted launch.

## READY

- [x] Separate review branch from verified UX 2.0; existing MVP/UX branches and 23 screenshots preserved.
- [x] Configurable closed/allowlisted access, maximum five approved non-admin accounts, verified email required, Quick profile enforced in the pilot, durable trial quotas.
- [x] Controlled verification/recovery, expiry, digest-only database tokens, replay/resend checks, rate limits and session revocation.
- [x] Authenticated metadata export and password-confirmed deletion, including queued/running jobs, integration links, evidence and temporary checkout cleanup.
- [x] Actual public import, real worker results, deterministic source-backed question, evidence link, change analysis and dependency graph.
- [x] Cross-account HTTP isolation, forged export ID rejection, CSRF, existing security regressions and backend-only PostgreSQL grants checked.
- [x] Worker crash/replacement and bounded unavailable-repository retries; database outage returns safe health failure and recovers after restart.
- [x] Encrypted backup restored into a distinct empty local database; matching account/project/scan/file data and password verification; wrong key and nonempty target refused.
- [x] Private operational CLI, idle/busy heartbeat, queue/failure/disk/mail/cleanup information and existing sanitized logs.
- [x] Changed screens reviewed; desktop/mobile and both themes checked, with automated accessibility results recorded.
- [x] Optional minimal feedback, five-user script and blank measurement templates prepared. Billing and private imports remain unavailable.

## BLOCKED

- [ ] Build and boot the final Docker image and both Compose services on a machine with a working Docker engine. Local verification used native PHP/PostgreSQL, not containers.
- [ ] Run the hosted web → database → worker → result flow on the intended plans/region, including hard disk/memory caps, process restarts and load with representative participant repositories.
- [ ] Verify the public HTTPS origin, secure cookies behind the actual proxy and database TLS/certificate validation. Local HTTP cannot prove these gates.
- [ ] Configure the approved SMTP relay/secret file, then explicitly approve and test real email delivery, link preservation, spam placement and sender authentication. Only the controlled local mailbox was used.
- [ ] Approve and schedule encrypted off-host backups, restore/deletion reconciliation, retention and monitoring notifications. The rehearsal is real; an unattended hosted schedule is not installed.
- [ ] Supply operator/business identity and an approved support/privacy contact, publish reviewed notices, and resolve the Slovenian business/legal questions in the report.
- [ ] Complete the hosted network-timeout/remote-deletion fault tests. Local process deadlines, missing remotes and retry exhaustion are tested; a provider network incident was not induced.
- [ ] Obtain actual usability/value evidence from five consenting people. The prepared templates contain no results.

## REQUIRES FOUNDER APPROVAL

- [ ] Review the UX PR and pilot PR in their stacked branch order; approve any merge. Merging to an auto-deploying branch also requires deployment approval.
- [ ] Choose hosting/region and a spending limit; authorize paid infrastructure and provider accounts. The guide gives a budget, not a purchase.
- [ ] Approve the canonical URL, DNS/TLS changes and publication of endpoints.
- [ ] Authorize a backed-up, reviewed migration on the explicitly selected staging database; production changes require separate authorization.
- [ ] Approve email sender, SMTP provider/credentials, deliverability tests and live messages.
- [ ] Approve five participant addresses, invitation wording, outreach and research/feedback retention. Do not send invitations automatically.
- [ ] Confirm the operating business, support hours, incident contact and privacy/consumer terms with qualified Slovenian advisers.
- [ ] Consider billing only after value validation and a separate approval for durable paid entitlements, tax/invoicing, cancellation/refunds and a payment provider.

Next: review the PR and read PILOT-DEPLOYMENT-GUIDE.md. Resolve the hosted and email blockers before inviting anyone. No cloud deployment, merge, live email, production migration or outreach is authorized by this checklist.
