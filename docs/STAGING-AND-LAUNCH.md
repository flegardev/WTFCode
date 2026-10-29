> Pilot 3.0: the current gated procedure is [PILOT-DEPLOYMENT-GUIDE.md](../PILOT-DEPLOYMENT-GUIDE.md). Do not use historical “deploy now” steps or replay old migrations to open the pilot. A web deployment alone does not run the worker.

# Local staging and launch gates

## Reproduce with Docker Compose

Prerequisites: Docker Engine with Compose v2, enough disk for the pinned analyzer binaries, and outbound GitHub/package-registry access. The stack runs a web process, separate durable worker and isolated PostgreSQL 17. It binds the UI only to `127.0.0.1:8088`; no cloud service is changed.

PowerShell:

```powershell
$env:STAGING_DB_PASSWORD = [guid]::NewGuid().ToString('N')
docker compose -f compose.staging.yaml config --quiet
docker compose -f compose.staging.yaml up --build -d
docker compose -f compose.staging.yaml logs --tail 50 web worker
Invoke-RestMethod http://127.0.0.1:8088/health.php
```

Keep the password in a local secret store if restarting the stack in a new shell. Changing it does not change an existing database volume's password. Never point this compose file at a production database. The canonical schema initializes **new** volumes; it does not upgrade an existing database. Do not use `down -v` on data you need to retain.

The app image pins its unprivileged user to UID/GID 10001, matching the temporary filesystem ownership. One worker is intentional: it bounds concurrent memory and clone storage. Each worker gets a 512 MiB temporary filesystem, providing a hard disk ceiling beyond the post-clone 100 MB repository check. The full container must be built and smoke-tested before calling this recipe verified; Docker is unavailable in the current Windows environment.

## Native staging verified in this task

The application also runs with PHP 8.5, Node 24, npm's lockfile installation, PostgreSQL 17 and the canonical schema. Development dependencies and real analysis workers must be installed. Set `APP_ENV=testing`, a disposable localhost `DATABASE_URL`, `SESSION_DRIVER=database`, a private `WTF_STORAGE_PATH`, deterministic explanations, and private access disabled. Run `php -S 127.0.0.1:8876 -t public` and `php tools/scan-worker.php --loop` as separate processes. Use `APP_ENV=production` on an isolated clone-cleanup check. No production environment variables are required.

## Required before production approval

- Active PostgreSQL database with reviewed grants, TLS, schema and capacity. Existing app uses server-side PHP sessions, **not** Supabase Auth. The privileged runtime role makes application ownership checks essential.
- Long-running worker host with restart supervision, CPU/RAM limits, bounded temporary disk, current parser/Git security updates and outbound network rules. Web-request runtimes alone cannot run this queue.
- Back up database using `pg_dump --format=custom` into encrypted private storage; rehearse `pg_restore` into a fresh database and verify owner/session/job boundaries there. Do not restore over production for a drill.
- Review migrations manually. Historical Supabase migrations contain provider-specific roles and a hardcoded `postgres` database name; `tools/migrate.php` is not yet a general-purpose local upgrade tool. Native tests here use the canonical fresh schema. No schema change is required for this PR.
- Verify callback URLs, GitHub App read-only permissions, installation revocation, selected private repository ownership, token cleanup and worker isolation in a dedicated staging App. Only then approve `WTF_PRIVATE_REPOSITORIES_ENABLED=true`.
- Verify backup restore, crashed-worker lease recovery under actual hosting, queue-age monitoring, low-disk alerts, sanitized centralized logs and an incident contact. `/health.php` checks core database/queue/session schema; it does not certify that a worker is alive.
- Authentication recovery, email verification and account deletion/export are still launch blockers. Publish operator/legal contact, privacy retention and support policy. Test login/registration rate limits behind the chosen trusted proxy.
- Agree on a durable entitlement store and storage-retention budget before billing. Trial limits are enforced; proposed paid profiles are policy definitions, not purchasable grants. No payment processor or paid resource is activated.

## Costs: assumptions, not purchased capacity

Local staging adds no hosting bill. For an initial hosted pilot, budget roughly **$40–60/month**: Supabase Pro from $25/month plus an assumed $15–35/month total for a web process and always-on worker. Parser workloads may need more RAM; benchmark the real container before selecting a plan. Domain, taxes, email and overages are excluded. Free Supabase projects currently pause after one inactive week and are limited to two active projects, so free capacity is unsuitable as an availability promise.

Sources checked 28 October 2026: [Supabase pricing](https://supabase.com/pricing), [Render pricing](https://render.com/pricing), [Render background workers](https://render.com/docs/background-workers). The compute allowance above is a planning estimate, not a verified provider quote. Customer AI spend is zero with the deterministic path.
