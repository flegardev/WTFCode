# Alpha scorecard — fastapi-full-stack

- Repository: https://github.com/tiangolo/full-stack-fastapi-template.git
- Commit: `162344da111e833b30892728372ab95331f06873`
- Reviewer: OpenAI Codex, manual static/source review
- Review date: 2026-08-20

- Stack accuracy (1–5): 5
- Architecture accuracy (1–5): 4
- Feature accuracy (1–5): 4
- Route accuracy (1–5): 5
- Database understanding (1–5): 4
- Service understanding (1–5): 4
- Feature tracing (1–5): 3
- Blast radius usefulness (1–5): 3
- Git/change explanation (1–5): 2
- Security usefulness (1–5): 3
- Explanation clarity (1–5): 4
- Evidence quality (1–5): 4
- False-positive control (1–5): 4
- Overall usefulness (1–5): 4

## Primary usefulness question

Did WTFCode teach you something useful that you did not understand before? **Yes**

What did it teach you?

It condensed a split React/FastAPI/Docker app into 18 concrete backend routes, auth/admin/profile areas, and the `item`/`user` data boundary.

## Trust question

Would you trust WTFCode before asking an AI coding agent to change this repository? **Mostly**

Reason:

The core architecture and routes are strong. Product intelligence reached its bounded limit, tree-sitter was partial, and generated client types create trace noise.

## Confusion and truthfulness

What output confused you the most?

Search evidence initially included a release script; ALPHA-015 removed support scripts from runtime feature evidence. Remaining Search evidence comes from the items UI.

What did WTFCode confidently say that turned out to be wrong?

No current strong claim was disproved. The pre-fix support-script contribution made confidence less trustworthy than the final evidence warrants.

## Manual probes

- Relevant feature traces and hop review: Login/registration/admin/profile start correctly; generated frontend client hops dominate some traces.
- Where-does-this-button-go sample: Signup and item CRUD map to the correct FastAPI route modules; partial UI-to-client-generation hops remain.
- Blast-radius sample: Auth dependencies identify several protected routes, but transitive recommendations remain broad.
- Can-I-delete-this sample: Generated clients and API route files require different caution; neither is declared safe from absence alone.
- Database/auth/service checks: `item`, `user`, Postgres, Sentry, token/login routes, and admin protection were source-confirmed.
- Confidence calibration samples: Strong Login/Registration/Admin/Profile are correct; localhost is an internal development endpoint, not a third-party service insight.
- Explanation-mode comparison: Clear distinction between user-level areas and FastAPI dependency/route terminology.
- Test recommendation quality: Auth token, password recovery, admin/user permissions, and item CRUD are relevant.
- Safe prompt quality: Good scope and schema warnings; generated client regeneration should be mentioned explicitly.

## Product value

- Approximate import-to-first-useful-insight time: about 5 seconds
- Best insight WTFCode found: Admin behavior spans both a frontend route and protected FastAPI user endpoints rather than living in one admin module.
- Value category: ARCHITECTURE

Notes:

This review treats partial analysis as partial; it does not infer completeness from the 18 detected routes.
