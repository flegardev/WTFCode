# Controlled pilot deployment guide

Status: prepared, not deployed. No cloud resources, production migrations, DNS changes, live email or paid accounts were created. Read PILOT-LAUNCH-CHECKLIST.md first. The native localhost rehearsal is evidence about application behavior, not a container or hosted readiness claim.

## Architecture and budget

Use one PHP web container, one separately supervised worker container from the same image, and one managed PostgreSQL database. Keep one worker and five approved users. No Redis, external AI service, subscription integration or source-code execution is needed. PostgreSQL holds sessions, queue leases, results, tokens and feedback. Checkouts and process scratch data are expendable; never use a Vercel request as the persistent worker.

A practical candidate is a Render Docker web service plus Docker background worker, with a separate Supabase PostgreSQL project in an approved nearby region. This is a proposal, not a provisioned deployment. The smallest Render service plan is 0.5 CPU / 512 MB; its capacity for this image must be verified. Budget a 1 CPU / 2 GB worker until whole-process memory and representative source limits have been tested. The Compose rehearsal sets 512 MB web, 2 GB worker and a 512 MiB checkout tmpfs per container. These are proposed limits, not verified cloud capacity.

Published prices checked 28 October 2026: Render lists $7/month for 0.5 CPU / 512 MB and $25/month for 1 CPU / 2 GB; Supabase Pro starts at $25/month with the first project included and a Micro compute credit. Thus a 512 MB web + 2 GB worker + first Pro project budget is approximately **$57/month**, before tax, currency conversion, workspace/seat fees, overages, email, domains and separate backup storage. A second project in an existing paid organization can add compute cost; confirm the actual invoice preview. A 512 MB worker would reduce the base estimate to $39 but is not capacity-verified. Free Supabase projects can pause after a week of inactivity and do not replace the operational backup plan.

Sources: [Render pricing](https://render.com/pricing?trk=public_profile__reactions-text), [current compute plan IDs](https://render.com/docs/compute-plans), [Supabase pricing](https://supabase.com/pricing). Recheck prices and commercial workspace eligibility before approval. No spend is authorized here.

## Required configuration

Use provider secret storage; never commit actual participant addresses, database URLs, SMTP secrets or encryption keys. `.env.example` contains safe defaults. Set the same access policy on web and worker.

| Variable | Pilot setting |
| --- | --- |
| APP_ENV / APP_DEBUG | production / false on hosted services |
| APP_URL | Exact canonical HTTPS origin, no credentials, query or fragment; not an address supplied by the request |
| DATABASE_URL | Dedicated approved PostgreSQL database. For remote service use sslmode=verify-full and a trusted provider CA through libpq's PGSSLROOTCERT; verify the actual connection. Never put database credentials in the browser. |
| SESSION_DRIVER | database |
| WTF_STORAGE_PATH | Writable private directory outside public; disposable checkouts/process scratch, with a hard container/filesystem cap |
| WTF_PILOT_ACCESS | closed while preparing; allowlist only after approval and gates pass. open is for legacy/local development and is outside the verified external pilot policy. |
| WTF_PILOT_EMAILS | One to five approved addresses, comma-separated, private configuration |
| WTF_REQUIRE_EMAIL_VERIFICATION | true; existing unverified accounts must verify too |
| WTF_PRIVATE_REPOSITORIES_ENABLED | false |
| WTF_CODE_EXPLANATION_PROVIDER | deterministic |
| WTF_MAIL_TRANSPORT | disabled until approved; then mail with the approved SMTP relay. file is rejected outside local/testing loopback. |
| WTF_LIVE_MAIL_APPROVED | false until explicit live-message approval; then true for the approved test/send scope |
| WTF_MAIL_FROM | Approved authenticated sender address |
| WTF_ADMIN_EMAIL | Exact founder address, bootstrap only after its email is verified; leave blank after setup. No unverified signup can bootstrap an admin. |
| APP_VERSION | Deployed Git SHA |
| WTF_BACKUP_KEY | External base64-encoded 32-byte random key, backup operator only; store separately from backups |
| WTF_RESTORE_DATABASE_URL | Empty, distinct loopback database for the local rehearsal command only; never a live database |

Provider caches were content-addressed without an owner relationship. Closed/allowlisted operation now bypasses that accelerator, and maintenance/deletion clears legacy cache entries and files. Authoritative scan results remain. Do not reopen registration or re-enable shared caching for external users without a separate ownership/deletion review.

## Local isolated container rehearsal

A working Docker engine and Compose are required. They were unavailable on the verification workstation, so **image build, container boot, final image vulnerability audit and real cgroup/tmpfs behavior remain blocked**. CI validates the Compose structure; that is not a container boot test.

1. Review the image and both YAML files. Supply a disposable `STAGING_DB_PASSWORD` in the shell and optionally the approved test allowlist. Leave SMTP disabled. The database volume is a new staging volume; do not attach a production volume.
2. Run `docker compose -f compose.staging.yaml -f compose.pilot.yaml config --quiet`.
3. Run `docker compose -f compose.staging.yaml -f compose.pilot.yaml build` and then `up -d`. The web binding is loopback port 8088. Do not publish it through a tunnel or DNS.
4. A fresh volume loads `supabase/production-schema.sql`. Run `docker compose -f compose.staging.yaml -f compose.pilot.yaml exec web php tools/pilot-upgrade.php` twice to verify the reviewed additive upgrade is idempotent.
5. Check `/health.php`, worker container health, resource limits and private logs. Mail stays disabled: file transport is intentionally unavailable in production-mode containers. For a controlled test mailbox, use an explicitly isolated local/testing harness with loopback APP_URL, never a customer-facing service.
6. Run the real browser journey with two disposable addresses, real public import and worker processing, export/deletion and chaos tests. Check that web and worker storage both clean up, and inspect OS/container memory including Node children. Never execute the imported app or install its dependencies.

The native harness used PostgreSQL 18.6, PHP 8.5.12 and Node 24.19.0; CI/Compose use PostgreSQL 17 and CI PHP 8.3. On Windows, keep the PostgreSQL log **outside PGDATA**: an in-directory open log caused a sharing violation during crash recovery. Moving the log outside the data directory resolved recovery without disabling antivirus or changing security settings. Keep PHP's trusted CA configuration enabled; the local PHP installation needed a bundle exported from the Windows trusted-root store. TLS verification was not disabled.

## Schema and migration procedure

`tools/pilot-upgrade.php` initializes the canonical schema only when the users table is absent; otherwise it applies only `20261028172900_pilot_account_lifecycle`, under a transaction/advisory lock and version record. It does not replay historical provider-specific migrations. Do not use the old `tools/migrate.php` loop as the pilot upgrade procedure.

Review a backup and a schema diff first. The added lower-case email uniqueness index deliberately fails on historical case-colliding addresses; investigate and resolve identity ownership manually, never auto-delete/merge accounts. An old installation must already satisfy the UX 2.0 schema contract. The new tables and schema ledger have RLS enabled; browser-facing anon/authenticated/service_role roles receive no table/sequence privileges. This app uses PHP authentication, not Supabase Auth or a browser service-role key. A backend database role needs the documented application privileges; cloud role/TLS/advisor checks must be repeated on the approved project.

No production migration was run. Applying even this additive migration to a hosted database requires explicit authorization for that named database.

## Approved hosted deployment sequence

1. Founder approves provider/region, budget, source SHA, operator identity, support contact, retention and exact staging target. Obtain explicit authorization for cloud deployment and database changes. Configure review-branch auto-deploy suppression before pushing; `vercel.json` disables both review branches. Do not assume a merge into another branch is deployment-safe.
2. Build and audit the immutable Docker image on a working engine. Run both services with one worker, restart supervision, an init process and sufficient graceful-stop time. The worker handles SIGTERM when pcntl is present; forced termination still relies on durable lease recovery. An unhealthy Docker healthcheck is a signal, not automatic restart of a live but hung process; the operator must investigate/restart it.
3. Create only the approved services and dedicated database. Keep access closed and private imports disabled. Configure resource/disk caps, private storage, secret files and database TLS. Managed cloud providers do not automatically apply the local Compose limits; verify their equivalent controls before launch.
4. With approval and a verified backup, apply the reviewed schema/upgrade using the backend connection. Run provider security/performance advisors and inspect grants. Confirm no browser Data API access to application tables.
5. Configure the approved SMTP relay. The image includes msmtp and PHP's sendmail path. Mount a private `/etc/msmtprc`, readable by UID 10001, based on `docs/msmtp.example.conf`. Use TLS with certificate validation, a matching sender and provider credentials stored as a secret file. Provider-native secret-file mount paths may differ: wire the approved file into msmtp explicitly rather than assuming it is mounted at `/etc/msmtprc`. The file must be unavailable to the public web root. Consider granting the secret only to the worker service.
6. After separate live-message approval, send verification/recovery only to the founder's controlled approved address. Verify SPF/DKIM/DMARC configuration, actual receipt/spam behavior, fragment preservation, expiry and session revocation. `mail()` accepting a message proves handoff to the relay, not inbox delivery. The MTA adapter was prepared but **not live-tested**.
7. Run `/health.php` plus `php tools/pilot-status.php` privately. Test the complete hosted browser → API → database → worker → result journey, secure cookies behind the actual proxy, quotas, cross-owner access, deletion during work, restart recovery and hard resource limits. Repeat backup restore into a separate environment.
8. Only after all P0 gates pass, set the approved allowlist (maximum five), approve invitations and open access. No automatic invitations or outreach are implemented.

## Worker and operations

Start `php tools/scan-worker.php --loop --poll=3` under the service supervisor, never inside a web request. One worker handles scan and account-mail work. Quick analysis is enforced server-side in allowlist mode. Up to three job attempts use 30/60-second retry delays; the pilot lease is at most five minutes between checkpoints, so a crash may leave a user waiting until lease expiry plus backoff. The forced-crash test advanced only the disposable lease/backoff clock to avoid waiting; it did not change production timing.

Run `php tools/pilot-status.php` from a restricted operator shell. Check worker heartbeat, queued count, oldest queued age, failed/completed counts, scan duration, free storage, mail backlog/exhaustion, cleanup markers, legacy cache count and optional aggregate feedback. `/health.php` intentionally exposes only generic database/schema readiness and the version; it does not prove worker or email health. Heartbeats are refreshed at idle polls and scan checkpoints; a hard-killed worker ages out after five minutes. A normal exit removes its heartbeat.

Daily proposed checks: public health failure immediately, absent heartbeat after five minutes, oldest queued job over ten minutes, any failed scan/mail exhaustion, free temporary storage under 512 MiB, cleanup warnings or unexplained application errors. Provider stderr receives sanitized errors; inspect its restricted logs. Do not forward source, passwords or tokens to a third-party tracker. Alert routing and notifications are **not installed** and require an approved destination/provider. Queue metrics come from existing durable job rows; no browsing analytics are added.

Maintenance runs on the worker approximately once a minute between jobs. It removes orphaned/deleted-project checkouts, inactive checkouts older than two hours, expired sessions/tokens, delivery requests older than 24 hours, feedback older than 30 days, old heartbeat rows and legacy unowned caches. Deletion markers retain project IDs for 24 hours without user/email data; orphan detection continues after that. Cleanup failures log a safe warning and are retried. Web and worker filesystems can be separate: the worker must remain supervised so its own orphan files are removed. Retention jobs stop while the worker is offline.

For incidents, set `WTF_PILOT_ACCESS=closed` on both services and restart safely. Account export/deletion remains available to authenticated, unsuspended accounts. Do not expose diagnostic CLI output publicly or delete production data to make health green. Preserve sanitized evidence, identify impact and get founder approval for external communications. Suspension/privacy requests need operator handling through the approved contact.

## Backup, restore and rollback

Proposed policy for approval: one encrypted logical backup daily, retain seven days in a separate access-controlled storage account/location, keep the encryption key in a separate password manager/secret store, record backup success/failure, and rehearse restore weekly during the pilot. No off-host storage or unattended schedule was configured. Daily backups imply up to 24 hours of potential data loss; a one-hour recovery target is a planning goal, not a tested hosted SLA. The measured sub-second local restore is not a forecast for larger databases or provider provisioning.

The PHP operator tool requires matching PostgreSQL utilities on PATH. `php tools/pilot-backup.php backup /secure/path/backup.wtfb` runs pg_dump without credentials in arguments, then encrypts the custom-format archive with AES-256-GCM, a random nonce and authenticated format header. It refuses an existing destination or the public directory and supports archives up to 64 MiB. It uses a permission-restricted temporary plaintext archive; storage encryption and host access controls still matter. For a larger database, approve a streaming encrypted backup process rather than raising the cap blindly.

For rehearsal, create a new empty loopback database, set `WTF_RESTORE_DATABASE_URL` to it and keep APP_ENV=testing or local. Run `php tools/pilot-backup.php rehearse /secure/path/backup.wtfb`. It rejects the source database, non-loopback targets, nonempty target tables and an invalid authentication tag/key. It does not use --clean or overwrite a live database. Verify account/password recovery, project metadata, scans/files, schema ledger, RLS and grants; clear restored sessions, account tokens/delivery requests and stale leases before serving any restored environment. Keep email disabled and do not connect a worker to the rehearsal copy accidentally.

The real rehearsal restored one account, one project, one scan, 57 file records and two schema versions; row fingerprints and the restored password hash matched. A wrong key and a nonempty target were refused. The disposable key was discarded afterwards; the successful restored database and JSON evidence are the rehearsal record, not a reusable customer backup.

Deletion cascades remove active account/project/evidence/job/integration records, with temporary-file and unowned-cache cleanup. It does not uninstall a shared GitHub App installation or revoke other users' access; short-lived operation tokens remain governed by the existing finally-revocation path. Private imports are disabled. Mail already delivered to an inbox cannot be recalled. Backups can retain prior records until expiry. Keep a minimal restricted deletion-reconciliation record outside the database being backed up (reference/account IDs and deletion date, no source) for the backup retention window, with an approved policy. Reapply deletions and revoke restored sessions/tokens **before** reconnecting users or email. The external reconciliation procedure is manual and requires operator approval.

Rollback: close access, stop worker claims, retain an encrypted backup and identify jobs/leases. Redeploy the previous reviewed image/SHA only after confirming schema compatibility. The pilot schema is additive; leave extra columns/tables in place rather than running a destructive down migration. Keep access closed if old code cannot enforce verification/allowlist controls. Restore into a new database, validate and reconcile deletions, then switch only the approved services after authorization. Never overwrite the live database as a shortcut.
