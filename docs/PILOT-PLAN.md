# Pilot readiness implementation plan

Verified baseline: f873f83 (UX 2.0), a descendant of SaaS MVP d399e8e. PRs remain unmerged. The original UX evidence hashes match; no redesign is planned.

P0 implementation order:
1. Account lifecycle and pilot allowlist: hashed expiring single-use tokens, local controlled test mailbox plus opt-in MTA adapter, session revocation, protected export/deletion.
2. Queue operations: idle/busy liveness, deletion cleanup fences and durable cleanup tasks, private operations snapshot and readiness checks.
3. Isolated PostgreSQL restore/restart rehearsal, safe additive upgrade path, staging configuration and approval gates.
4. Real account/import/worker/browser journey, targeted changed-screen captures, dependency/security checks and review PR.

P1: minimal optional structured feedback and clear account/onboarding guidance. P2: billing, private repositories, teams and paid AI remain disabled.

No cloud deployment, production database change, paid infrastructure, outreach or live email. Public launch, email delivery, support contact, operator identity and provider selection require founder approval. Unverified hosted/container gates will remain blocked.
