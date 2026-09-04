# Asynchronous repository scans

Repository imports and rescans are durable database jobs. HTTP requests only validate ownership and repository identity, create or reuse one active job for the project, and return the user to the project page.

## Run the worker

Install both dependency sets first:

```bash
composer install
npm ci
```

For local development or a long-running worker service:

```bash
composer worker
```

To claim at most one available job, which is useful for a scheduler or a deployment check:

```bash
composer worker:once
```

The worker needs the same server-only database, GitHub App, storage, Git, Node.js, and analyzer configuration as the web application. Never expose its database URL or GitHub App private key to browser code.

## Runtime contract

- PostgreSQL enforces one queued or running job per project with a partial unique index. MySQL migration `010_async_scan_invariants.sql` enforces the same invariant with a functional unique index over active states; the project-row lock remains the first line of enqueue serialization.
- Project creation/rescan state and the corresponding job are committed atomically. Concurrent requests coalesce into the existing active job and retain the strongest requested analysis profile.
- Claims use a short `FOR UPDATE SKIP LOCKED` transaction. Repository cloning and analysis happen after the claim commits.
- Each claim receives an opaque 64-character lease token. Heartbeats, scan metadata, evidence association, and completion use token comparisons so an expired worker cannot commit over a newer attempt.
- Retry availability is evaluated with the database clock. Explicit lease recovery transactionally requeues eligible jobs or marks exhausted jobs and their projects failed; read-only status polling never mutates queue state.
- Expired leases return to the queue until `max_attempts` is reached. Errors shown to users are sanitized; worker and credential details are never returned by `scan-status.php`. A worker exits non-zero after a lost database connection so its supervisor can restart it safely.
- A failed rescan keeps the previous successful evidence browsable. A failed initial import is marked failed after its final attempt.
- Private repositories receive a fresh repository-scoped installation token for each working copy. The token is revoked after use and is never stored in the job row.
- Imported repository dependencies and source code are never executed. Analyzer processes receive bounded file content as untrusted input.

## Schema

For Supabase/PostgreSQL, apply the versioned files in `supabase/migrations/` with the normal linked-project migration workflow. The consolidated `supabase/production-schema.sql` contains the same queue fields for a fresh database.

Legacy MySQL installations apply:

```text
database/migrations/008_async_scan_queue.sql
database/migrations/009_change_guard_pairing.sql
database/migrations/010_async_scan_invariants.sql
```

Migration `010` removes impossible exhausted queued rows, coalesces duplicate active jobs, adds the queued-attempt budget check, and adds the active-job functional unique index. Apply all three migrations in order before starting the worker.

## Hosting boundary

`Dockerfile.vercel` remains the web image. A Vercel request is not a persistent worker process, so production queue processing must run as a separately supervised, long-running service or an external scheduler invoking `composer worker:once`. This repository prepares that worker command but does not claim that an external worker has been deployed.
