# Alpha scorecard — next-postgres-auth

- Repository: https://github.com/vercel/nextjs-postgres-nextauth-tailwindcss-template.git
- Commit: `fe026711c19ac3ee4589c86a738f59b84e611559`
- Reviewer: OpenAI Codex, manual static/source review
- Review date: 2026-08-20

- Stack accuracy (1–5): 5
- Architecture accuracy (1–5): 4
- Feature accuracy (1–5): 4
- Route accuracy (1–5): 5
- Database understanding (1–5): 3
- Service understanding (1–5): 5
- Feature tracing (1–5): 3
- Blast radius usefulness (1–5): 3
- Git/change explanation (1–5): 2
- Security usefulness (1–5): 3
- Explanation clarity (1–5): 4
- Evidence quality (1–5): 4
- False-positive control (1–5): 5
- Overall usefulness (1–5): 4

## Primary usefulness question

Did WTFCode teach you something useful that you did not understand before? **Yes**

What did it teach you?

It exposed the compact boundary between Auth.js, the catch-all auth route, Drizzle/Postgres, the seed endpoint, and dashboard search. The seed route is an immediate review target that is easy to miss from the project name alone.

## Trust question

Would you trust WTFCode before asking an AI coding agent to change this repository? **Mostly**

Reason:

The stack, five routes, login/search evidence, and Postgres service were source-confirmed. I would still read `lib/auth.ts`, `lib/db.ts`, and the target route because the trace does not prove every dynamic Auth.js callback or database effect.

## Confusion and truthfulness

What output confused you the most?

The high-level architecture is clearer than the trace: relationship output can contain unresolved UI/library names.

What did WTFCode confidently say that turned out to be wrong?

Before ALPHA-013, an optional lockfile package was called active AWS use. The fixed run reports only Postgres. Before ALPHA-021, the stack also omitted directly declared Auth.js.

## Manual probes

- Relevant feature traces and hop review: Login and Search start in the correct pages/components; Auth.js callback internals remain partially unresolved.
- Where-does-this-button-go sample: Search maps to `app/(dashboard)/search.tsx`; no backend endpoint is fabricated.
- Blast-radius sample: Useful candidates were found, but this small app did not produce a compelling high-risk central-symbol result.
- Can-I-delete-this sample: `lib/auth.ts` is convention/configuration-sensitive; conservative wording is appropriate.
- Database/auth/service checks: `products`, `users`, Auth.js, and Postgres were source-confirmed. No runtime AWS use exists.
- Confidence calibration samples: Strong Login and Search are correct; likely Postgres use is correct.
- Explanation-mode comparison: Vibe explains the gatekeeper role; technical mode names session/auth routing.
- Test recommendation quality: Login, session persistence, protected dashboard access, search, and seed-route authorization are relevant.
- Safe prompt quality: Names real auth/database files and warns against weakening routes or changing schema implicitly.

## Product value

- Approximate import-to-first-useful-insight time: about 3 seconds
- Best insight WTFCode found: A small dashboard template exposes a GET seed route beside Auth.js and durable product/user data.
- Value category: RISK

Notes:

The pinned Alpha clone has one fetched commit, so repository-specific change-intelligence scoring is necessarily weak.
