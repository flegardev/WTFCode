# Vercel container deployment

The repository is ready for Vercel's Dockerfile container runtime. Vercel detects `Dockerfile.vercel` at the repository root, builds the image, injects `PORT`, and routes requests to FrankenPHP. No `vercel.json` is required.

## Import settings

In **Vercel → Add New → Project**, import `flegardev/WTFCode` with these values:

| Field | Value |
| --- | --- |
| Project Name | `wtf-code` |
| Framework Preset | `Container` |
| Root Directory | `./` |
| Build Command | leave blank |
| Install Command | leave blank |
| Output Directory | leave blank |

Do not add a custom build command and do not rename `Dockerfile.vercel`.

## Production environment variables

Add these to **Production** before the first deployment:

| Name | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `DATABASE_URL` | Supabase **Session pooler** URL on port `5432` |
| `DB_SSLMODE` | `require` |
| `SESSION_DRIVER` | `database` |
| `WTF_STORAGE_PATH` | `/tmp/wtfcode` |
| `WTF_SCAN_PROFILE` | `quick` |

`PORT` is supplied by Vercel. `APP_URL` is optional; set it to the final `https://` production origin after the domain is known. The deterministic explanation provider is the default. Only add external explanation-provider variables if that data transfer is intentionally enabled and disclosed.

For the first administrator, set `WTF_ADMIN_EMAIL` to the exact account email before that account registers or logs in once. The successful login promotes that account and persists the flag; remove the variable after setup. Alternatively, run `php tools/promote-admin.php --email=owner@example.com` from a trusted PHP environment with the production `DATABASE_URL`.

Never add Supabase anon, publishable, or service-role keys. Never expose `DATABASE_URL` through client-side code or a `NEXT_PUBLIC_`/`VITE_` variable.

## First-deployment checks

1. Deploy from the Vercel dashboard after the Supabase schema and variables are ready.
2. Open `/health`; expect HTTP 200 with `"status":"ok"` and `"database":"ready"`.
3. Register a disposable account, log out/in, reload, import one small public GitHub repository, and verify the project remains after another deployment or cold start.
4. Check Function/Runtime Logs for structured application errors. Production responses must stay generic and must not display database credentials or stack traces.
5. Check response headers for CSP, `X-Content-Type-Options`, `Referrer-Policy`, clickjacking protection, and a session cookie with `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS.

Quick scans are the safe hosted default. Deep, Security, and Maximum can exceed synchronous request limits on larger repositories; move those profiles to durable background work before promising them as an unrestricted hosted feature.
